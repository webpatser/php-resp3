/*
  +----------------------------------------------------------------------+
  | php-resp3 — RESP3 wire-protocol parser                               |
  +----------------------------------------------------------------------+
  | State machine: switch-case dispatch + explicit stack for aggregates. |
  | Pause/resume safe — partial input never advances p->pos past         |
  | unconsumed bytes and never mutates user-visible zvals.               |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_smart_str.h"
#include "resp3_parser.h"

#include <string.h>
#include <errno.h>
#include <stdlib.h>

#define COMPACT_MIN_BYTES (16 * 1024)

static void frame_init(resp3_frame_t *f, char type, int64_t count)
{
	f->type = type;
	f->count = count;
	f->map_key_pending = (type == RESP3_TYPE_MAP || type == RESP3_TYPE_ATTRIBUTE) ? 1 : 0;
	f->pending_key = NULL;
	/* Clamp before the (uint32_t) cast. With finalize_length caps in place this
	 * is unreachable, but the explicit clamp is a defence-in-depth backstop. */
	uint32_t hint = 0;
	if (count > 0) {
		hint = count > (int64_t) UINT32_MAX ? UINT32_MAX : (uint32_t) count;
	}
	array_init_size(&f->accum, hint);
}

static void frame_dtor(resp3_frame_t *f)
{
	if (f->pending_key) {
		zend_string_release(f->pending_key);
		f->pending_key = NULL;
	}
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
	frame_init(&p->stack[p->depth], type, count);
	p->depth++;
	return 0;
}

/* Append a finished value to the top-of-stack aggregate, or, if the stack is empty,
 * land it as the completed top-level message. Takes ownership of *val. */
static int deliver_value(resp3_parser_t *p, zval *val, char *err, size_t err_len)
{
	/* Type of the value we're currently delivering. Starts as cur_type (the scalar that
	 * just produced this value) and gets overwritten to the popped aggregate type as we
	 * walk up the stack. */
	char value_type = p->cur_type;

	while (1) {
		if (p->depth == 0) {
			/* Top-level message complete. */
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
				/* Use string keys for maps when possible; fall back to numeric stringified key. */
				if (Z_TYPE_P(val) == IS_STRING) {
					top->pending_key = zend_string_copy(Z_STR_P(val));
				} else {
					zend_string *s = zval_get_string(val);
					top->pending_key = s; /* takes the new ref */
				}
				zval_ptr_dtor(val);
				ZVAL_UNDEF(val);
				top->map_key_pending = 0;
				top->count--;
				/* Map entry not yet complete — wait for value. */
				return 0;
			} else {
				zend_hash_update(Z_ARRVAL(top->accum), top->pending_key, val);
				zend_string_release(top->pending_key);
				top->pending_key = NULL;
				ZVAL_UNDEF(val);
				top->map_key_pending = 1;
				top->count--;
			}
		} else {
			/* Array, set, push: indexed append. */
			zend_hash_next_index_insert(Z_ARRVAL(top->accum), val);
			ZVAL_UNDEF(val);
			top->count--;
		}

		if (top->count > 0) {
			return 0; /* aggregate not yet complete */
		}

		/* Aggregate complete — pop and deliver upward. */
		zval finished;
		ZVAL_COPY_VALUE(&finished, &top->accum);
		ZVAL_UNDEF(&top->accum);

		char popped_type = top->type;

		if (top->pending_key) {
			zend_string_release(top->pending_key);
			top->pending_key = NULL;
		}
		p->depth--;

		if (popped_type == RESP3_TYPE_ATTRIBUTE) {
			/* Attribute payload attaches to parser, then we wait for the next value. */
			zval_ptr_dtor(&p->attributes);
			ZVAL_COPY_VALUE(&p->attributes, &finished);
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
		return 0;
	}
	return deliver_value(p, &empty, err, err_len);
}

/* Parse the line accumulator (without trailing CRLF) for the type currently being read. */
static int finalize_line(resp3_parser_t *p, char *err, size_t err_len)
{
	const char *s = p->line_acc.s ? ZSTR_VAL(p->line_acc.s) : "";
	size_t       n = p->line_acc.s ? ZSTR_LEN(p->line_acc.s) : 0;
	zval val;
	ZVAL_UNDEF(&val);

	switch (p->cur_type) {
		case RESP3_TYPE_SIMPLE_STRING:
			ZVAL_STRINGL(&val, s, n);
			break;

		case RESP3_TYPE_ERROR:
			/* Return error text as a string for now. Userland wraps in exception in resp3.c. */
			ZVAL_STRINGL(&val, s, n);
			break;

		case RESP3_TYPE_INTEGER: {
			char *end = NULL;
			errno = 0;
			char buf[32];
			if (n >= sizeof(buf)) {
				snprintf(err, err_len, "integer too long");
				return -1;
			}
			memcpy(buf, s, n);
			buf[n] = '\0';
			long long v = strtoll(buf, &end, 10);
			if (errno == ERANGE || end != buf + n || n == 0) {
				snprintf(err, err_len, "invalid integer");
				return -1;
			}
			ZVAL_LONG(&val, (zend_long)v);
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
			char buf[64];
			if (n >= sizeof(buf)) {
				snprintf(err, err_len, "double too long");
				return -1;
			}
			memcpy(buf, s, n);
			buf[n] = '\0';
			double d;
			if (n == 3 && (memcmp(buf, "inf", 3) == 0)) {
				d = ZEND_INFINITY;
			} else if (n == 4 && memcmp(buf, "-inf", 4) == 0) {
				d = -ZEND_INFINITY;
			} else if (n == 3 && memcmp(buf, "nan", 3) == 0) {
				d = ZEND_NAN;
			} else {
				char *end = NULL;
				errno = 0;
				d = strtod(buf, &end);
				if (end != buf + n || n == 0) {
					snprintf(err, err_len, "invalid double");
					return -1;
				}
			}
			ZVAL_DOUBLE(&val, d);
			break;
		}

		case RESP3_TYPE_BIG_NUMBER:
			/* PHP has no native bignum — return as string. */
			ZVAL_STRINGL(&val, s, n);
			break;

		default:
			snprintf(err, err_len, "unknown line type 0x%02x", (unsigned char)p->cur_type);
			return -1;
	}

	smart_str_free(&p->line_acc);
	return deliver_value(p, &val, err, err_len);
}

/* Parse the int_acc as the length/count for the just-consumed length-prefixed type. */
static int finalize_length(resp3_parser_t *p, char *err, size_t err_len)
{
	int64_t v = p->int_neg ? -p->int_acc : p->int_acc;

	switch (p->cur_type) {
		case RESP3_TYPE_BULK_STRING:
		case RESP3_TYPE_VERBATIM_STRING:
		case RESP3_TYPE_BLOB_ERROR:
			if (v < 0) {
				/* RESP2 null bulk: $-1\r\n */
				zval val;
				ZVAL_NULL(&val);
				return deliver_value(p, &val, err, err_len);
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
				/* RESP2 null array: *-1\r\n */
				zval val;
				ZVAL_NULL(&val);
				return deliver_value(p, &val, err, err_len);
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

/* Finalize a bulk-string-family value once bulk_len bytes have been collected. */
static int finalize_bulk(resp3_parser_t *p, char *err, size_t err_len)
{
	const char *s = p->line_acc.s ? ZSTR_VAL(p->line_acc.s) : "";
	size_t       n = p->line_acc.s ? ZSTR_LEN(p->line_acc.s) : 0;
	zval val;
	ZVAL_UNDEF(&val);

	switch (p->cur_type) {
		case RESP3_TYPE_BULK_STRING:
			ZVAL_STRINGL(&val, s, n);
			break;

		case RESP3_TYPE_VERBATIM_STRING:
			/* Format is xxx:payload — leave parsing to the userland wrapper in resp3.c. */
			ZVAL_STRINGL(&val, s, n);
			break;

		case RESP3_TYPE_BLOB_ERROR:
			ZVAL_STRINGL(&val, s, n);
			break;

		default:
			snprintf(err, err_len, "bulk on non-bulk type");
			return -1;
	}

	smart_str_free(&p->line_acc);
	return deliver_value(p, &val, err, err_len);
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

void resp3_parser_init(resp3_parser_t *p, size_t max_depth, int64_t max_bulk, int64_t max_count)
{
	memset(p, 0, sizeof(*p));
	p->state = RESP3_S_TYPE;
	p->max_depth = max_depth ? max_depth : 100;
	p->max_bulk  = max_bulk  > 0 ? max_bulk  : RESP3_DEFAULT_MAX_BULK;
	p->max_count = max_count > 0 ? max_count : RESP3_DEFAULT_MAX_COUNT;
	smart_str_alloc(&p->buf, 0, 0);
	ZVAL_NULL(&p->attributes);
	ZVAL_UNDEF(&p->completed);
	p->completed_type = 0;
}

void resp3_parser_dtor(resp3_parser_t *p)
{
	smart_str_free(&p->buf);
	smart_str_free(&p->line_acc);
	for (size_t i = 0; i < p->depth; i++) {
		frame_dtor(&p->stack[i]);
	}
	if (p->stack) {
		efree(p->stack);
		p->stack = NULL;
	}
	zval_ptr_dtor(&p->attributes);
	ZVAL_NULL(&p->attributes);
	if (Z_TYPE(p->completed) != IS_UNDEF) {
		zval_ptr_dtor(&p->completed);
		ZVAL_UNDEF(&p->completed);
	}
}

void resp3_parser_reset(resp3_parser_t *p)
{
	size_t md  = p->max_depth;
	int64_t mb = p->max_bulk;
	int64_t mc = p->max_count;
	resp3_parser_dtor(p);
	resp3_parser_init(p, md, mb, mc);
}

void resp3_parser_feed(resp3_parser_t *p, const char *bytes, size_t len)
{
	if (len == 0) return;
	smart_str_appendl(&p->buf, bytes, len);
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

resp3_parse_result_t resp3_parser_step(resp3_parser_t *p, char *err, size_t err_len)
{
	if (Z_TYPE(p->completed) != IS_UNDEF) {
		/* Caller hasn't consumed previous result yet — defensive. */
		return RESP3_PARSE_COMPLETE;
	}

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
				if (strchr(valid_types, t) == NULL) {
					snprintf(err, err_len,
						"unknown RESP wire type 0x%02x; the parser handles "
						"server-to-client RESP3 traffic, not inline commands "
						"or arbitrary bytes",
						(unsigned char) t);
					return RESP3_PARSE_ERROR;
				}
				p->cur_type = t;
				start_line_for(p, t);
				break;
			}

			case RESP3_S_LEN: {
				while (p->pos < len) {
					char c = buf[p->pos];
					if (c == '\r') {
						if (p->int_digits == 0 && !p->int_neg) {
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
				int rc = finalize_bulk(p, err, err_len);
				if (rc < 0) return RESP3_PARSE_ERROR;
				if (rc == 1) return RESP3_PARSE_COMPLETE;
				p->state = RESP3_S_TYPE;
				break;
			}

			case RESP3_S_LINE: {
				while (p->pos < len) {
					char c = buf[p->pos];
					if (c == '\r') {
						p->pos++;
						p->state = RESP3_S_LINE_LF;
						goto state_loop;
					}
					if (p->line_acc.s != NULL && ZSTR_LEN(p->line_acc.s) >= RESP3_MAX_INLINE_LINE) {
						snprintf(err, err_len, "inline line too long (>%d)", RESP3_MAX_INLINE_LINE);
						return RESP3_PARSE_ERROR;
					}
					smart_str_appendc(&p->line_acc, c);
					p->pos++;
				}
				maybe_compact(p);
				return RESP3_PARSE_NEED_MORE;
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
