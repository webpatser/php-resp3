/*
  +----------------------------------------------------------------------+
  | php-resp3: RESP3 wire-protocol parser                                |
  +----------------------------------------------------------------------+
  | Author: Christoph Kempen <christoph@downsized.nl>                    |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "ext/spl/spl_exceptions.h"
#include "Zend/zend_exceptions.h"
#include "php_resp3.h"
#include "resp3_parser.h"
#include "resp3_arginfo.h"

static zend_class_entry *resp3_parser_ce = NULL;
static zend_class_entry *resp3_redis_exception_ce = NULL;
static zend_class_entry *resp3_verbatim_string_ce = NULL;
static zend_class_entry *resp3_push_message_ce = NULL;
static zend_object_handlers resp3_parser_object_handlers;

typedef struct {
	resp3_parser_t parser;
	zend_object    std;
} resp3_parser_object;

static inline resp3_parser_object *resp3_parser_from_obj(zend_object *obj)
{
	return (resp3_parser_object *)((char *)obj - XtOffsetOf(resp3_parser_object, std));
}

#define Z_RESP3_PARSER_P(zv) resp3_parser_from_obj(Z_OBJ_P(zv))

static zend_object *resp3_parser_create(zend_class_entry *ce)
{
	resp3_parser_object *intern = zend_object_alloc(sizeof(resp3_parser_object), ce);

	zend_object_std_init(&intern->std, ce);
	object_properties_init(&intern->std, ce);
	intern->std.handlers = &resp3_parser_object_handlers;

	/* Initialise with the default caps so an instance created without running
	 * __construct (reflection, newInstanceWithoutConstructor) is still usable. */
	resp3_parser_init(&intern->parser, RESP3_DEFAULT_MAX_DEPTH,
		RESP3_DEFAULT_MAX_BULK, RESP3_DEFAULT_MAX_COUNT, 0);
	return &intern->std;
}

static void resp3_parser_free(zend_object *obj)
{
	resp3_parser_object *intern = resp3_parser_from_obj(obj);
	resp3_parser_dtor(&intern->parser);
	zend_object_std_dtor(&intern->std);
}

/* Redis error prefix: the leading token when it is all [A-Z0-9_] and ends at
 * a space or at the end of the message ("ERR", "WRONGTYPE", "MOVED"), else "". */
static zend_string *resp3_error_prefix(const zend_string *msg)
{
	const char *raw = ZSTR_VAL(msg);
	size_t      len = ZSTR_LEN(msg);
	size_t      i   = 0;

	while (i < len && raw[i] != ' ') {
		unsigned char c = (unsigned char) raw[i];
		if (!((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '_')) {
			return ZSTR_EMPTY_ALLOC();
		}
		i++;
	}
	if (i == 0) {
		return ZSTR_EMPTY_ALLOC();
	}
	return zend_string_init(raw, i, 0);
}

/* Build a Resp3\RedisException with a binary-safe message and the given prefix.
 * Neither string is consumed. */
static void resp3_make_redis_exception(zval *out, zend_string *message, zend_string *prefix)
{
	object_init_ex(out, resp3_redis_exception_ce);
	zend_update_property_str(resp3_redis_exception_ce, Z_OBJ_P(out),
		"message", sizeof("message") - 1, message);
	zend_update_property_str(resp3_redis_exception_ce, Z_OBJ_P(out),
		"prefix", sizeof("prefix") - 1, prefix);
}

/* Throw a parser fault as Resp3\RedisException with prefix "PROTOCOL". */
static void resp3_throw_protocol_error(const char *err)
{
	zval ex;
	zend_string *message = zend_strpprintf(0, "RESP3 parse error: %s", err);
	zend_string *prefix  = zend_string_init("PROTOCOL", sizeof("PROTOCOL") - 1, 0);

	resp3_make_redis_exception(&ex, message, prefix);
	zend_string_release(message);
	zend_string_release(prefix);
	zend_throw_exception_object(&ex);
}

/* Contract with resp3_parser.c: replace *val in place with its userland
 * wrapper. `-`/`!` strings become Resp3\RedisException, `=` strings become
 * Resp3\VerbatimString, `>` arrays become Resp3\PushMessage. Anything else
 * is left untouched. `!-1` and `=-1` are protocol errors in the parser and
 * never reach this function; only `$-1` and `*-1` are null.
 */
void resp3_wrap_reply(char type, zval *val)
{
	zval wrapped;

	if ((type == '-' || type == '!') && Z_TYPE_P(val) == IS_STRING) {
		zend_string *prefix = resp3_error_prefix(Z_STR_P(val));

		resp3_make_redis_exception(&wrapped, Z_STR_P(val), prefix);
		zend_string_release(prefix);
		zval_ptr_dtor(val);
		ZVAL_COPY_VALUE(val, &wrapped);
		return;
	}

	/* Verbatim string: split "xxx:payload" into type + value and wrap.
	 * The wire format is `=<len>\r\n<3-char prefix>:<payload>\r\n`. The type
	 * prefix is server-supplied untrusted input; only 3 ASCII alnum chars are
	 * accepted. Anything else falls back to type="" with the full payload in
	 * value so consumers still see the bytes without trusting them as a tag. */
	if (type == '=' && Z_TYPE_P(val) == IS_STRING) {
		zend_string *s   = Z_STR_P(val);
		const char  *raw = ZSTR_VAL(s);
		size_t       len = ZSTR_LEN(s);

		bool valid_prefix = (len >= 4 && raw[3] == ':');
		if (valid_prefix) {
			for (int i = 0; i < 3; i++) {
				unsigned char c = (unsigned char) raw[i];
				if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9'))) {
					valid_prefix = false;
					break;
				}
			}
		}

		zend_string *type_s = valid_prefix
			? zend_string_init(raw, 3, 0)
			: ZSTR_EMPTY_ALLOC();
		zend_string *value_s = valid_prefix
			? zend_string_init(raw + 4, len - 4, 0)
			: zend_string_copy(s);

		object_init_ex(&wrapped, resp3_verbatim_string_ce);
		zend_update_property_str(resp3_verbatim_string_ce, Z_OBJ(wrapped),
			"type", sizeof("type") - 1, type_s);
		zend_update_property_str(resp3_verbatim_string_ce, Z_OBJ(wrapped),
			"value", sizeof("value") - 1, value_s);

		zend_string_release(type_s);
		zend_string_release(value_s);
		zval_ptr_dtor(val);
		ZVAL_COPY_VALUE(val, &wrapped);
		return;
	}

	/* Push: wrap the array payload so consumers can route on instanceof.
	 * zend_update_property adds its own reference, so the old zval is
	 * released afterwards. */
	if (type == '>' && Z_TYPE_P(val) == IS_ARRAY) {
		object_init_ex(&wrapped, resp3_push_message_ce);
		zend_update_property(resp3_push_message_ce, Z_OBJ(wrapped),
			"payload", sizeof("payload") - 1, val);
		zval_ptr_dtor(val);
		ZVAL_COPY_VALUE(val, &wrapped);
	}
}

PHP_FUNCTION(resp3_version)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_STRING(PHP_RESP3_VERSION);
}

PHP_METHOD(Resp3_Parser, __construct)
{
	zend_long max_depth    = RESP3_DEFAULT_MAX_DEPTH;
	zend_long max_bulk     = (zend_long) RESP3_DEFAULT_MAX_BULK;
	zend_long max_count    = (zend_long) RESP3_DEFAULT_MAX_COUNT;
	bool      queue_pushes = false;

	ZEND_PARSE_PARAMETERS_START(0, 4)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_depth)
		Z_PARAM_LONG(max_bulk)
		Z_PARAM_LONG(max_count)
		Z_PARAM_BOOL(queue_pushes)
	ZEND_PARSE_PARAMETERS_END();

	/* Recursive zval destruction of a very deep reply can exhaust the C stack,
	 * so depth is capped well below that point. */
	if (max_depth < 1 || max_depth > RESP3_MAX_DEPTH_CEILING) {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"maxDepth must be between 1 and %d", RESP3_MAX_DEPTH_CEILING);
		RETURN_THROWS();
	}
	if (max_bulk < 1 || max_bulk > ((zend_long) 2) * 1024 * 1024 * 1024) {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"maxBulk must be between 1 and 2147483648 (2 GiB)");
		RETURN_THROWS();
	}
	if (max_count < 1 || max_count > 100000000) {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"maxAggregateCount must be between 1 and 100000000");
		RETURN_THROWS();
	}

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);

	/* create_object already initialised the parser with defaults. Calling
	 * __construct (again) discards any state and applies the given caps. */
	resp3_parser_dtor(&intern->parser);
	resp3_parser_init(&intern->parser, (size_t) max_depth, (int64_t) max_bulk,
		(int64_t) max_count, queue_pushes ? 1 : 0);
}

PHP_METHOD(Resp3_Parser, feed)
{
	char *bytes;
	size_t bytes_len;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(bytes, bytes_len)
	ZEND_PARSE_PARAMETERS_END();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_feed(&intern->parser, bytes, bytes_len);
}

/* Run the state machine unless a reply is already waiting. Returns the step
 * result; on RESP3_PARSE_ERROR a PROTOCOL exception has been thrown. A latched
 * parser error is reported by resp3_parser_step itself until reset(). */
static resp3_parse_result_t resp3_drive(resp3_parser_t *p)
{
	if (Z_TYPE(p->completed) != IS_UNDEF) {
		return RESP3_PARSE_COMPLETE;
	}

	char err[128] = {0};
	resp3_parse_result_t rc = resp3_parser_step(p, err, sizeof(err));

	if (rc == RESP3_PARSE_ERROR) {
		resp3_throw_protocol_error(err);
	}
	return rc;
}

/* Drive the state machine until a complete message lands in p->completed,
 * an error is hit (throws), or the buffer runs out (returns false). */
PHP_METHOD(Resp3_Parser, hasNext)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parse_result_t rc = resp3_drive(&intern->parser);

	if (rc == RESP3_PARSE_ERROR) {
		RETURN_THROWS();
	}
	RETURN_BOOL(rc == RESP3_PARSE_COMPLETE);
}

PHP_METHOD(Resp3_Parser, next)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_t *p = &intern->parser;
	resp3_parse_result_t rc = resp3_drive(p);

	if (rc == RESP3_PARSE_ERROR) {
		RETURN_THROWS();
	}
	if (rc == RESP3_PARSE_NEED_MORE) {
		zend_throw_exception_ex(spl_ce_LogicException, 0,
			"next() called with no complete message available; check hasNext() first");
		RETURN_THROWS();
	}

	/* COMPLETE: the parser already wrapped errors, verbatim strings and pushes
	 * via resp3_wrap_reply(). Hand the value over and clear the slot. */
	zval out;
	ZVAL_COPY_VALUE(&out, &p->completed);
	ZVAL_UNDEF(&p->completed);
	RETURN_COPY_VALUE(&out);
}

/* Queue mode: report whether a push is waiting. Drives the state machine first
 * so a push buffered ahead of (or without) a regular reply is found. */
PHP_METHOD(Resp3_Parser, hasPush)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_t *p = &intern->parser;

	if (resp3_parser_has_push(p)) {
		RETURN_TRUE;
	}
	if (resp3_drive(p) == RESP3_PARSE_ERROR) {
		RETURN_THROWS();
	}
	RETURN_BOOL(resp3_parser_has_push(p));
}

PHP_METHOD(Resp3_Parser, nextPush)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_t *p = &intern->parser;

	if (!resp3_parser_has_push(p)) {
		if (resp3_drive(p) == RESP3_PARSE_ERROR) {
			RETURN_THROWS();
		}
		if (!resp3_parser_has_push(p)) {
			RETURN_NULL();
		}
	}

	resp3_parser_shift_push(p, return_value);
}

PHP_METHOD(Resp3_Parser, reset)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_reset(&intern->parser);
}

PHP_METHOD(Resp3_VerbatimString, __construct)
{
	zend_string *type;
	zend_string *value;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STR(type)
		Z_PARAM_STR(value)
	ZEND_PARSE_PARAMETERS_END();

	zend_update_property_str(resp3_verbatim_string_ce, Z_OBJ_P(ZEND_THIS),
		"type", sizeof("type") - 1, type);
	zend_update_property_str(resp3_verbatim_string_ce, Z_OBJ_P(ZEND_THIS),
		"value", sizeof("value") - 1, value);
}

PHP_METHOD(Resp3_PushMessage, __construct)
{
	zval *payload;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(payload)
	ZEND_PARSE_PARAMETERS_END();

	zend_update_property(resp3_push_message_ce, Z_OBJ_P(ZEND_THIS),
		"payload", sizeof("payload") - 1, payload);
}

PHP_METHOD(Resp3_Parser, lastAttributes)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	if (Z_TYPE(intern->parser.attributes) != IS_ARRAY) {
		RETURN_NULL();
	}

	/* One-shot consume: hand the array to the caller, then clear the slot so a
	 * second call returns null (until the parser sees the next `|` frame). This
	 * avoids stale attributes leaking from a prior reply into a later context. */
	zval out;
	ZVAL_COPY_VALUE(&out, &intern->parser.attributes);
	ZVAL_NULL(&intern->parser.attributes);
	RETURN_COPY_VALUE(&out);
}

PHP_MINIT_FUNCTION(resp3)
{
	resp3_parser_ce = register_class_Resp3_Parser();
	resp3_parser_ce->create_object = resp3_parser_create;
	resp3_parser_ce->default_object_handlers = &resp3_parser_object_handlers;

	memcpy(&resp3_parser_object_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	resp3_parser_object_handlers.offset = XtOffsetOf(resp3_parser_object, std);
	resp3_parser_object_handlers.free_obj = resp3_parser_free;
	resp3_parser_object_handlers.clone_obj = NULL;

	resp3_redis_exception_ce = register_class_Resp3_RedisException(spl_ce_RuntimeException);
	resp3_verbatim_string_ce = register_class_Resp3_VerbatimString();
	resp3_push_message_ce    = register_class_Resp3_PushMessage();

	return SUCCESS;
}

PHP_MINFO_FUNCTION(resp3)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "resp3 support", "enabled");
	php_info_print_table_row(2, "version", PHP_RESP3_VERSION);
	php_info_print_table_end();
}

zend_module_entry resp3_module_entry = {
	STANDARD_MODULE_HEADER,
	"resp3",
	ext_functions,
	PHP_MINIT(resp3),
	NULL,                  /* MSHUTDOWN */
	NULL,                  /* RINIT */
	NULL,                  /* RSHUTDOWN */
	PHP_MINFO(resp3),
	PHP_RESP3_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_RESP3
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(resp3)
#endif
