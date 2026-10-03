/*
  +----------------------------------------------------------------------+
  | php-resp3: RESP3 wire-protocol parser                               |
  +----------------------------------------------------------------------+
  | State machine: switch-case dispatch + explicit stack for aggregates. |
  | Pause/resume safe: partial input never advances p->pos past         |
  | unconsumed bytes and never mutates user-visible zvals.               |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_smart_str.h"
#include "zend_strtod.h"
#include "php_resp3.h"
#include "resp3_parser.h"

#include <string.h>
#include <errno.h>
#include <stdlib.h>

#define COMPACT_MIN_BYTES (16 * 1024)

static void frame_init(resp3_frame_t *f, char type, int64_t count, int nested)
{
	f->type = type;
	f->count = count;
	f->map_key_pending = (type == RESP3_TYPE_MAP || type == RESP3_TYPE_ATTRIBUTE) ? 1 : 0;
	ZVAL_UNDEF(&f->pending_key);
	/* The declared count is unverified wire input. Only use it as a capped
	 * size hint; the table grows by doubling as children actually arrive.
	 * Map and attribute frames count 2 * pairs, but hold one entry per pair. */
	int64_t entries = f->map_key_pending ? count / 2 : count;
	int64_t cap = nested ? RESP3_PREALLOC_HINT_CAP_NESTED : RESP3_PREALLOC_HINT_CAP;
	uint32_t hint = 0;
	if (entries > 0) {
		hint = (uint32_t) (entries > cap ? cap : entries);
	}
	array_init_size(&f->accum, hint);
}

static void frame_dtor(resp3_frame_t *f)
{
	zval_ptr_dtor(&f->pending_key);
	ZVAL_UNDEF(&f->pending_key);
	zval_ptr_dtor(&f->accum);
	ZVAL_UNDEF(&f->accum);
}

static int stack_push(resp3_parser_t *p, char type, int64_t count, char *err, size_t err_len)
{
	if (p->depth >= p->max_depth) {
		snprintf(err, err_len, "max depth exceeded (%zu)", p->max_depth);
		return -1;
	}
	if (p->depth == p->cap) {
		size_t new_cap = p->cap == 0 ? 8 : p->cap * 2;
		resp3_frame_t *nstack = erealloc(p->stack, new_cap * sizeof(resp3_frame_t));
		p->stack = nstack;
		p->cap = new_cap;
	}
	frame_init(&p->stack[p->depth], type, count, p->depth > 0);
	p->depth++;
	return 0;
}

/* Turn a just-parsed map/attribute key into the held key: IS_LONG stays an
 * integer index, every other scalar becomes a string that is later inserted via
 * the symtable API so numeric strings ("123") land on the canonical integer key,
 * exactly as a PHP array literal would store them. Consumes *val. */
static int take_map_key(resp3_frame_t *top, zval *val, char *err, size_t err_len)
{
	switch (Z_TYPE_P(val)) {
		case IS_STRING:
		case IS_LONG:
			ZVAL_COPY_VALUE(&top->pending_key, val);
			ZVAL_UNDEF(val);
			return 0;
		case IS_DOUBLE:
		case IS_TRUE:
		case IS_FALSE:
		case IS_NULL:
			ZVAL_STR(&top->pending_key, zval_get_string(val));
			zval_ptr_dtor(val);
			ZVAL_UNDEF(val);
			return 0;
		default:
			snprintf(err, err_len, "aggregate or wrapped map key not supported");
			zval_ptr_dtor(val);
			ZVAL_UNDEF(val);
			return -1;
	}
}

/* Append a finished value to the top-of-stack aggregate, or, if the stack is empty,
 * land it as the completed top-level message (or queue it, for a push in queue mode).
 * Takes ownership of *val, also on error. Returns 1 when a top-level reply landed in
 * p->completed, 0 when parsing should continue, -1 on error. */
static int deliver_value(resp3_parser_t *p, zval *val, char *err, size_t err_len)
{
	/* Type of the value we're currently delivering. Starts as cur_type (the scalar that
	 * just produced this value) and gets overwritten to the popped aggregate type as we
	 * walk up the stack. */
	char value_type = p->cur_type;

	while (1) {
		if (p->depth == 0) {
			/* Top-level message complete. */
			if (value_type == RESP3_TYPE_PUSH && Z_TYPE_P(val) == IS_ARRAY) {
				resp3_wrap_reply(RESP3_TYPE_PUSH, val);
				if (p->queue_pushes) {
					if (Z_TYPE(p->push_queue) == IS_UNDEF) {
						array_init(&p->push_queue);
					}
					HashTable *q = Z_ARRVAL(p->push_queue);
					uint32_t used = zend_hash_num_elements(q);
					/* Bound the queue: a consumer that never drains it must not
					 * let a server grow it without limit. */
					if ((int64_t) (used - p->push_head) >= p->max_count) {
						snprintf(err, err_len, "push queue limit exceeded; drain with nextPush()");
						zval_ptr_dtor(val);
						ZVAL_UNDEF(val);
						return -1;
					}
					/* Explicit key: entries are never deleted before the table is
					 * cleaned, so the element count is the next free slot. */
					zend_hash_index_add_new(q, used, val);
					ZVAL_UNDEF(val);
					/* Out-of-band: keep parsing toward the next reply. */
					p->state = RESP3_S_TYPE;
					return 0;
				}
			}
			ZVAL_COPY_VALUE(&p->completed, val);
			ZVAL_UNDEF(val);
			p->completed_type = value_type;
			/* Reset state so the next call to step() begins a fresh message. */
			p->state = RESP3_S_TYPE;
			return 1;
		}

		resp3_frame_t *top = &p->stack[p->depth - 1];

		if (top->type == RESP3_TYPE_MAP || top->type == RESP3_TYPE_ATTRIBUTE) {
			if (top->map_key_pending) {
				if (take_map_key(top, val, err, err_len) < 0) {
					return -1;
				}
				top->map_key_pending = 0;
				top->count--;
				/* Map entry not yet complete; wait for value. */
				return 0;
			}
			if (Z_TYPE(top->pending_key) == IS_LONG) {
				zend_hash_index_update(Z_ARRVAL(top->accum), (zend_ulong) Z_LVAL(top->pending_key), val);
			} else {
				zend_symtable_update(Z_ARRVAL(top->accum), Z_STR(top->pending_key), val);
			}
			ZVAL_UNDEF(val);
			zval_ptr_dtor(&top->pending_key);
			ZVAL_UNDEF(&top->pending_key);
			top->map_key_pending = 1;
			top->count--;
		} else {
			/* Array, set, push: indexed append. */
			zend_hash_next_index_insert(Z_ARRVAL(top->accum), val);
			ZVAL_UNDEF(val);
			top->count--;
		}

		if (top->count > 0) {
			return 0; /* aggregate not yet complete */
		}

		/* Aggregate complete: pop and deliver upward. */
		zval finished;
		ZVAL_COPY_VALUE(&finished, &top->accum);
		ZVAL_UNDEF(&top->accum);

		char popped_type = top->type;

		zval_ptr_dtor(&top->pending_key);
		ZVAL_UNDEF(&top->pending_key);
		p->depth--;

		if (popped_type == RESP3_TYPE_ATTRIBUTE) {
			/* Attribute payload attaches to parser, then we wait for the next value. */
			zval_ptr_dtor(&p->attributes);
			ZVAL_COPY_VALUE(&p->attributes, &finished);
			if (p->depth == 0) {
				p->attr_fresh = 1;
			}
			return 0;
		}

		ZVAL_COPY_VALUE(val, &finished);
		value_type = popped_type;
		/* Loop and deliver this finished aggregate to the parent (or top level). */
	}
}

/* Empty-aggregate fast path: handle *0 / %0 / ~0 / >0 / |0 immediately. */
static int handle_empty_aggregate(resp3_parser_t *p, char type, char *err, size_t err_len)
{
	zval empty;
	array_init(&empty);
	if (type == RESP3_TYPE_ATTRIBUTE) {
		zval_ptr_dtor(&p->attributes);
		ZVAL_COPY_VALUE(&p->attributes, &empty);
		if (p->depth == 0) {
			p->attr_fresh = 1;
		}
		return 0;
	}
	return deliver_value(p, &empty, err, err_len);
}

/* Shared tail for every string-valued reply (+ - ( $ ! =): takes ownership of s,
 * wraps errors and verbatim strings at any depth, then delivers. */
static int deliver_string(resp3_parser_t *p, zend_string *s, char *err, size_t err_len)
{
	zval val;
	ZVAL_STR(&val, s);
	switch (p->cur_type) {
		case RESP3_TYPE_ERROR:
		case RESP3_TYPE_BLOB_ERROR:
		case RESP3_TYPE_VERBATIM_STRING:
			resp3_wrap_reply(p->cur_type, &val);
			break;
		default:
			break;
	}
	return deliver_value(p, &val, err, err_len);
}

/* Strict RESP3 integer: optional '-', then 1..19 decimal digits, nothing else.
 * No whitespace, no '+', no locale. */
static int parse_integer(const char *s, size_t n, zend_long *out)
{
	size_t i = 0;
	if (n > 0 && s[0] == '-') {
		i = 1;
	}
	size_t digits = n - i;
	if (digits == 0 || digits > RESP3_HARD_DIGIT_LIMIT) {
		return -1;
	}
	for (size_t j = i; j < n; j++) {
		if (s[j] < '0' || s[j] > '9') {
			return -1;
		}
	}
	char buf[RESP3_HARD_DIGIT_LIMIT + 2];
	memcpy(buf, s, n);
	buf[n] = '\0';
	char *end = NULL;
	errno = 0;
	zend_long v = ZEND_STRTOL(buf, &end, 10);
	if (errno == ERANGE || end != buf + n) {
		return -1;
	}
	*out = v;
	return 0;
}

/* Strict RESP3 double: exactly inf, -inf, nan, -nan, or a decimal number made of
 * [0-9.eE+-] that starts with a digit or '-' and is fully consumed by the
 * locale-independent zend_strtod(). Rejects whitespace, hex and "infinity". */
static int parse_double(const char *s, size_t n, double *out)
{
	if (n == 3 && memcmp(s, "inf", 3) == 0) {
		*out = ZEND_INFINITY;
		return 0;
	}
	if (n == 4 && memcmp(s, "-inf", 4) == 0) {
		*out = -ZEND_INFINITY;
		return 0;
	}
	if ((n == 3 && memcmp(s, "nan", 3) == 0) || (n == 4 && memcmp(s, "-nan", 4) == 0)) {
		*out = ZEND_NAN;
		return 0;
	}
	char buf[64];
	if (n == 0 || n >= sizeof(buf)) {
		return -1;
	}
	if (!((s[0] >= '0' && s[0] <= '9') || s[0] == '-')) {
		return -1;
	}
	for (size_t i = 0; i < n; i++) {
		char c = s[i];
		if (!((c >= '0' && c <= '9') || c == '.' || c == 'e' || c == 'E' || c == '+' || c == '-')) {
			return -1;
		}
	}
	memcpy(buf, s, n);
	buf[n] = '\0';
	const char *end = NULL;
	double d = zend_strtod(buf, &end);
	if (end != buf + n) {
		return -1;
	}
	*out = d;
	return 0;
}

/* Parse the line accumulator (without trailing CRLF) for the type currently being read. */
static int finalize_line(resp3_parser_t *p, char *err, size_t err_len)
{
	switch (p->cur_type) {
		case RESP3_TYPE_SIMPLE_STRING:
		case RESP3_TYPE_ERROR:
		case RESP3_TYPE_BIG_NUMBER: /* PHP has no native bignum: returned as string. */
			/* Zero-copy handoff of the accumulated bytes. */
			return deliver_string(p, smart_str_extract(&p->line_acc), err, err_len);
		default:
			break;
	}

	const char *s = p->line_acc.s ? ZSTR_VAL(p->line_acc.s) : "";
	size_t       n = p->line_acc.s ? ZSTR_LEN(p->line_acc.s) : 0;
	zval val;

	switch (p->cur_type) {
		case RESP3_TYPE_INTEGER: {
			zend_long v;
			if (parse_integer(s, n, &v) < 0) {
				snprintf(err, err_len, "invalid integer");
				return -1;
			}
			ZVAL_LONG(&val, v);
			break;
		}

		case RESP3_TYPE_NULL:
			if (n != 0) {
				snprintf(err, err_len, "null type with payload");
				return -1;
			}
			ZVAL_NULL(&val);
			break;

		case RESP3_TYPE_BOOLEAN:
			if (n != 1 || (s[0] != 't' && s[0] != 'f')) {
				snprintf(err, err_len, "invalid boolean");
				return -1;
			}
			ZVAL_BOOL(&val, s[0] == 't');
			break;

		case RESP3_TYPE_DOUBLE: {
			double d;
			if (parse_double(s, n, &d) < 0) {
				snprintf(err, err_len, "invalid double");
				return -1;
			}
			ZVAL_DOUBLE(&val, d);
			break;
		}

		default:
			snprintf(err, err_len, "unknown line type 0x%02x", (unsigned char)p->cur_type);
			return -1;
	}

	smart_str_free(&p->line_acc);
	return deliver_value(p, &val, err, err_len);
}

/* RESP2 null for a length-prefixed type: only $-1 (null bulk) and *-1 (null
 * array) exist on the wire. Every other negative length, including -1 on
 * ! = ~ > % |, is rejected by finalize_length as a protocol error.
 * deliver_value only resets the state at top level (depth 0); a null nested in
 * an aggregate must advance to the next element's type byte here, or the
 * machine re-enters LEN_LF and misreads that byte as a missing LF. */
static int deliver_length_null(resp3_parser_t *p, char *err, size_t err_len)
{
	zval val;
	ZVAL_NULL(&val);
	p->state = RESP3_S_TYPE;
	return deliver_value(p, &val, err, err_len);
}

/* Parse the int_acc as the length/count for the just-consumed length-prefixed type. */
static int finalize_length(resp3_parser_t *p, char *err, size_t err_len)
{
	int64_t v = p->int_neg ? -p->int_acc : p->int_acc;

	/* Negative lengths: only the RESP2 nulls $-1 and *-1 are valid. This also
	 * rejects -0, which the LEN state lets through as int_neg with value 0. */
	if (p->int_neg) {
		if (v == -1 && (p->cur_type == RESP3_TYPE_BULK_STRING || p->cur_type == RESP3_TYPE_ARRAY)) {
			return deliver_length_null(p, err, err_len);
		}
		snprintf(err, err_len, "invalid negative length");
		return -1;
	}

	switch (p->cur_type) {
		case RESP3_TYPE_BULK_STRING:
		case RESP3_TYPE_VERBATIM_STRING:
		case RESP3_TYPE_BLOB_ERROR:
			if (v < 0) {
				snprintf(err, err_len, "invalid negative length");
				return -1;
			}
			if (v > p->max_bulk) {
				snprintf(err, err_len, "bulk too large (%lld > %lld)", (long long) v, (long long) p->max_bulk);
				return -1;
			}
			p->bulk_len = v;
			p->bulk_read = 0;
			p->state = RESP3_S_BULK_DATA;
			return 0;

		case RESP3_TYPE_ARRAY:
		case RESP3_TYPE_SET:
		case RESP3_TYPE_PUSH:
			if (v < 0) {
				snprintf(err, err_len, "invalid negative length");
				return -1;
			}
			if (v > p->max_count) {
				snprintf(err, err_len, "aggregate too large (%lld > %lld)", (long long) v, (long long) p->max_count);
				return -1;
			}
			if (v == 0) {
				p->state = RESP3_S_TYPE;
				return handle_empty_aggregate(p, p->cur_type, err, err_len);
			}
			if (stack_push(p, p->cur_type, v, err, err_len) < 0) {
				return -1;
			}
			p->state = RESP3_S_TYPE;
			return 0;

		case RESP3_TYPE_MAP:
		case RESP3_TYPE_ATTRIBUTE:
			if (v < 0) {
				snprintf(err, err_len, "negative map/attribute count");
				return -1;
			}
			/* Cap before the * 2 multiplication; we track 2*pairs entries. */
			if (v > p->max_count / 2) {
				snprintf(err, err_len, "aggregate too large (%lld pairs > %lld limit)",
					(long long) v, (long long) (p->max_count / 2));
				return -1;
			}
			if (v == 0) {
				p->state = RESP3_S_TYPE;
				return handle_empty_aggregate(p, p->cur_type, err, err_len);
			}
			if (stack_push(p, p->cur_type, v * 2, err, err_len) < 0) {
				return -1;
			}
			p->state = RESP3_S_TYPE;
			return 0;

		default:
			snprintf(err, err_len, "length on non-length type 0x%02x", (unsigned char)p->cur_type);
			return -1;
	}
}

/* Finalize a bulk-string-family value once its payload and CRLF are consumed.
 * Takes ownership of s. */
static int finalize_bulk(resp3_parser_t *p, zend_string *s, char *err, size_t err_len)
{
	switch (p->cur_type) {
		case RESP3_TYPE_BULK_STRING:
		case RESP3_TYPE_VERBATIM_STRING:
		case RESP3_TYPE_BLOB_ERROR:
			return deliver_string(p, s, err, err_len);
		default:
			zend_string_release(s);
			snprintf(err, err_len, "bulk on non-bulk type");
			return -1;
	}
}

/* Compact the rolling buffer if we've consumed enough to make it worthwhile. */
static void maybe_compact(resp3_parser_t *p)
{
	if (!p->buf.s) return;
	size_t len = ZSTR_LEN(p->buf.s);
	if (p->pos > COMPACT_MIN_BYTES && p->pos > len / 2) {
		memmove(ZSTR_VAL(p->buf.s), ZSTR_VAL(p->buf.s) + p->pos, len - p->pos);
		ZSTR_LEN(p->buf.s) = len - p->pos;
		ZSTR_VAL(p->buf.s)[ZSTR_LEN(p->buf.s)] = '\0';
		p->pos = 0;
	}
}

void resp3_parser_init(resp3_parser_t *p, size_t max_depth, int64_t max_bulk, int64_t max_count, int queue_pushes)
{
	memset(p, 0, sizeof(*p));
	p->state = RESP3_S_TYPE;
	p->max_depth = max_depth ? max_depth : 100;
	p->max_bulk  = max_bulk  > 0 ? max_bulk  : RESP3_DEFAULT_MAX_BULK;
	p->max_count = max_count > 0 ? max_count : RESP3_DEFAULT_MAX_COUNT;
	p->queue_pushes = queue_pushes ? 1 : 0;
	smart_str_alloc(&p->buf, 0, 0);
	ZVAL_NULL(&p->attributes);
	ZVAL_UNDEF(&p->completed);
	ZVAL_UNDEF(&p->push_queue);
	p->completed_type = 0;
}

void resp3_parser_dtor(resp3_parser_t *p)
{
	smart_str_free(&p->buf);
	smart_str_free(&p->line_acc);
	for (size_t i = 0; i < p->depth; i++) {
		frame_dtor(&p->stack[i]);
	}
	p->depth = 0;
	if (p->stack) {
		efree(p->stack);
		p->stack = NULL;
	}
	p->cap = 0;
	zval_ptr_dtor(&p->attributes);
	ZVAL_NULL(&p->attributes);
	zval_ptr_dtor(&p->completed);
	ZVAL_UNDEF(&p->completed);
	zval_ptr_dtor(&p->push_queue);
	ZVAL_UNDEF(&p->push_queue);
	p->push_head = 0;
}

void resp3_parser_reset(resp3_parser_t *p)
{
	size_t md  = p->max_depth;
	int64_t mb = p->max_bulk;
	int64_t mc = p->max_count;
	int qp     = p->queue_pushes;
	resp3_parser_dtor(p);
	resp3_parser_init(p, md, mb, mc, qp);
}

void resp3_parser_feed(resp3_parser_t *p, const char *bytes, size_t len)
{
	if (len == 0) return;
	smart_str_appendl(&p->buf, bytes, len);
}

int resp3_parser_has_push(resp3_parser_t *p)
{
	return Z_TYPE(p->push_queue) == IS_ARRAY
		&& p->push_head < zend_hash_num_elements(Z_ARRVAL(p->push_queue));
}

void resp3_parser_shift_push(resp3_parser_t *p, zval *out)
{
	ZVAL_NULL(out);
	if (!resp3_parser_has_push(p)) {
		return;
	}
	HashTable *ht = Z_ARRVAL(p->push_queue);
	zval *entry = zend_hash_index_find(ht, p->push_head);
	if (entry) {
		/* Move the reference out; the slot keeps a null until the table drains. */
		ZVAL_COPY_VALUE(out, entry);
		ZVAL_NULL(entry);
	}
	p->push_head++;
	/* O(1) per shift: slots are never deleted one by one, the whole table is
	 * cleaned once every entry has been handed out. */
	if (p->push_head == zend_hash_num_elements(ht)) {
		zend_hash_clean(ht);
		p->push_head = 0;
	}
}

/* Begin a new line/length read. p->cur_type already set. */
static void start_line_for(resp3_parser_t *p, char type)
{
	smart_str_free(&p->line_acc);
	switch (type) {
		case RESP3_TYPE_BULK_STRING:
		case RESP3_TYPE_VERBATIM_STRING:
		case RESP3_TYPE_BLOB_ERROR:
		case RESP3_TYPE_ARRAY:
		case RESP3_TYPE_SET:
		case RESP3_TYPE_PUSH:
		case RESP3_TYPE_MAP:
		case RESP3_TYPE_ATTRIBUTE:
			p->int_acc = 0;
			p->int_neg = 0;
			p->int_digits = 0;
			p->state = RESP3_S_LEN;
			break;
		default:
			p->state = RESP3_S_LINE;
			break;
	}
}

/* The state machine proper. Every RESP3_PARSE_ERROR it returns is latched by
 * resp3_parser_step(), the single public exit point. */
static resp3_parse_result_t parser_run(resp3_parser_t *p, char *err, size_t err_len)
{
	const char *buf = p->buf.s ? ZSTR_VAL(p->buf.s) : NULL;
	size_t       len = p->buf.s ? ZSTR_LEN(p->buf.s) : 0;

	for (;;) {
		switch (p->state) {
			case RESP3_S_TYPE: {
				if (p->pos >= len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				char t = buf[p->pos++];
				/* Validate the type byte against the legitimate RESP3 prefix set.
				 * Anything else (including ASCII letters that look like inline
				 * commands such as "PING\r\n") is a server-to-client protocol
				 * violation, not parser input we should try to interpret. */
				static const char valid_types[] = "+-:$*_,#(!=%~|>";
				/* Use memchr rather than strchr: strchr also matches the
				 * string's terminating NUL, so a 0x00 type byte would
				 * spuriously pass the whitelist. memchr bounds the search to
				 * the literal type characters and rejects NUL like any other
				 * unknown byte. */
				if (memchr(valid_types, t, sizeof(valid_types) - 1) == NULL) {
					snprintf(err, err_len,
						"unknown RESP wire type 0x%02x; the parser handles "
						"server-to-client RESP3 traffic, not inline commands "
						"or arbitrary bytes",
						(unsigned char) t);
					return RESP3_PARSE_ERROR;
				}
				/* Attributes annotate the message that follows them. A new
				 * top-level message drops attributes left over from an earlier
				 * reply, unless an attribute frame immediately preceded it. */
				if (p->depth == 0 && t != RESP3_TYPE_ATTRIBUTE) {
					if (!p->attr_fresh) {
						zval_ptr_dtor(&p->attributes);
						ZVAL_NULL(&p->attributes);
					}
					p->attr_fresh = 0;
				}
				p->cur_type = t;
				start_line_for(p, t);
				break;
			}

			case RESP3_S_LEN: {
				while (p->pos < len) {
					char c = buf[p->pos];
					if (c == '\r') {
						/* A lone '-' has int_neg set but no digits; it must not
						 * reach finalize_length as -0 == 0. */
						if (p->int_digits == 0) {
							snprintf(err, err_len, "empty length");
							return RESP3_PARSE_ERROR;
						}
						p->pos++;
						p->state = RESP3_S_LEN_LF;
						goto state_loop;
					}
					if (c == '-' && p->int_acc == 0 && p->int_digits == 0 && !p->int_neg) {
						p->int_neg = 1;
						p->pos++;
						continue;
					}
					if (c < '0' || c > '9') {
						snprintf(err, err_len, "non-digit in length");
						return RESP3_PARSE_ERROR;
					}
					/* No leading zeros: "0" alone is valid, "007" and "-01" are not. */
					if (p->int_digits > 0 && p->int_acc == 0) {
						snprintf(err, err_len, "leading zero in length");
						return RESP3_PARSE_ERROR;
					}
					if (++p->int_digits > RESP3_HARD_DIGIT_LIMIT) {
						snprintf(err, err_len, "length has too many digits (>%d)", RESP3_HARD_DIGIT_LIMIT);
						return RESP3_PARSE_ERROR;
					}
					/* Detect signed-int64 overflow before performing the multiply-add. */
					if (p->int_acc > (INT64_MAX - 9) / 10) {
						snprintf(err, err_len, "length out of range");
						return RESP3_PARSE_ERROR;
					}
					p->int_acc = p->int_acc * 10 + (c - '0');
					p->pos++;
				}
				maybe_compact(p);
				return RESP3_PARSE_NEED_MORE;
			}

			case RESP3_S_LEN_LF: {
				if (p->pos >= len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				if (buf[p->pos] != '\n') {
					snprintf(err, err_len, "expected LF after CR in length");
					return RESP3_PARSE_ERROR;
				}
				p->pos++;
				int rc = finalize_length(p, err, err_len);
				if (rc < 0) return RESP3_PARSE_ERROR;
				if (rc == 1) return RESP3_PARSE_COMPLETE;
				/* 0: parser advanced (now expecting bulk data or next type) */
				/* refresh local view in case smart_str grew (unlikely here) */
				buf = p->buf.s ? ZSTR_VAL(p->buf.s) : NULL;
				len = p->buf.s ? ZSTR_LEN(p->buf.s) : 0;
				break;
			}

			case RESP3_S_BULK_DATA: {
				/* Fast path: the whole payload plus its CRLF is already in buf
				 * and nothing has been copied to line_acc yet. Build the string
				 * straight from buf in one copy. p->pos is only advanced after
				 * the trailing CRLF is verified, so a partial frame never takes
				 * this branch and pause/resume stays on the slow path below. */
				if (p->bulk_read == 0 && (int64_t)(len - p->pos) >= p->bulk_len + 2) {
					const char *data = buf + p->pos;
					size_t n = (size_t) p->bulk_len;
					if (data[n] != '\r') {
						snprintf(err, err_len, "expected CR after bulk payload");
						return RESP3_PARSE_ERROR;
					}
					if (data[n + 1] != '\n') {
						snprintf(err, err_len, "expected LF after CR in bulk");
						return RESP3_PARSE_ERROR;
					}
					zend_string *s = zend_string_init(data, n, 0);
					p->pos += n + 2;
					int rc = finalize_bulk(p, s, err, err_len);
					if (rc < 0) return RESP3_PARSE_ERROR;
					if (rc == 1) return RESP3_PARSE_COMPLETE;
					p->state = RESP3_S_TYPE;
					break;
				}
				/* Slow path: payload split across feeds, accumulate in line_acc. */
				int64_t need = p->bulk_len - p->bulk_read;
				int64_t avail = (int64_t)(len - p->pos);
				int64_t take = need < avail ? need : avail;
				if (take > 0) {
					smart_str_appendl(&p->line_acc, buf + p->pos, (size_t)take);
					p->pos += (size_t)take;
					p->bulk_read += take;
					/* smart_str_appendl on line_acc doesn't touch buf, no refresh needed */
				}
				if (p->bulk_read < p->bulk_len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				p->state = RESP3_S_BULK_CR;
				break;
			}

			case RESP3_S_BULK_CR: {
				if (p->pos >= len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				if (buf[p->pos] != '\r') {
					snprintf(err, err_len, "expected CR after bulk payload");
					return RESP3_PARSE_ERROR;
				}
				p->pos++;
				p->state = RESP3_S_BULK_LF;
				break;
			}

			case RESP3_S_BULK_LF: {
				if (p->pos >= len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				if (buf[p->pos] != '\n') {
					snprintf(err, err_len, "expected LF after CR in bulk");
					return RESP3_PARSE_ERROR;
				}
				p->pos++;
				/* Zero-copy handoff of the accumulated payload. */
				int rc = finalize_bulk(p, smart_str_extract(&p->line_acc), err, err_len);
				if (rc < 0) return RESP3_PARSE_ERROR;
				if (rc == 1) return RESP3_PARSE_COMPLETE;
				p->state = RESP3_S_TYPE;
				break;
			}

			case RESP3_S_LINE: {
				if (p->pos >= len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				const char *start = buf + p->pos;
				const char *cr = memchr(start, '\r', len - p->pos);
				size_t run = cr ? (size_t)(cr - start) : len - p->pos;
				size_t have = p->line_acc.s ? ZSTR_LEN(p->line_acc.s) : 0;
				if (have + run > RESP3_MAX_INLINE_LINE) {
					snprintf(err, err_len, "inline line too long (>%d)", RESP3_MAX_INLINE_LINE);
					return RESP3_PARSE_ERROR;
				}
				if (run > 0) {
					smart_str_appendl(&p->line_acc, start, run);
					p->pos += run;
				}
				if (!cr) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				p->pos++; /* the CR */
				p->state = RESP3_S_LINE_LF;
				break;
			}

			case RESP3_S_LINE_LF: {
				if (p->pos >= len) {
					maybe_compact(p);
					return RESP3_PARSE_NEED_MORE;
				}
				if (buf[p->pos] != '\n') {
					snprintf(err, err_len, "expected LF after CR in line");
					return RESP3_PARSE_ERROR;
				}
				p->pos++;
				int rc = finalize_line(p, err, err_len);
				if (rc < 0) return RESP3_PARSE_ERROR;
				p->state = RESP3_S_TYPE;
				if (rc == 1) return RESP3_PARSE_COMPLETE;
				break;
			}
		}
		state_loop:;
	}
}

resp3_parse_result_t resp3_parser_step(resp3_parser_t *p, char *err, size_t err_len)
{
	/* After a protocol error pos, the stack and line_acc sit mid-message;
	 * resuming would parse garbage. Stay failed until reset(). */
	if (p->errored) {
		snprintf(err, err_len, "parser is in error state; call reset()");
		return RESP3_PARSE_ERROR;
	}

	if (Z_TYPE(p->completed) != IS_UNDEF) {
		/* Caller hasn't consumed previous result yet (defensive). */
		return RESP3_PARSE_COMPLETE;
	}

	resp3_parse_result_t rc = parser_run(p, err, err_len);
	if (rc == RESP3_PARSE_ERROR) {
		p->errored = 1;
	}
	return rc;
}
