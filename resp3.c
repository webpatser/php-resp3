/*
  +----------------------------------------------------------------------+
  | php-resp3 — RESP3 wire-protocol parser                               |
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

	/* parser fields zeroed by zend_object_alloc; explicit init happens in __construct */
	return &intern->std;
}

static void resp3_parser_free(zend_object *obj)
{
	resp3_parser_object *intern = resp3_parser_from_obj(obj);
	resp3_parser_dtor(&intern->parser);
	zend_object_std_dtor(&intern->std);
}

PHP_FUNCTION(resp3_version)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_STRING(PHP_RESP3_VERSION);
}

PHP_METHOD(Resp3_Parser, __construct)
{
	zend_long max_depth = 100;
	zend_long max_bulk  = (zend_long) RESP3_DEFAULT_MAX_BULK;
	zend_long max_count = (zend_long) RESP3_DEFAULT_MAX_COUNT;

	ZEND_PARSE_PARAMETERS_START(0, 3)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(max_depth)
		Z_PARAM_LONG(max_bulk)
		Z_PARAM_LONG(max_count)
	ZEND_PARSE_PARAMETERS_END();

	if (max_depth < 1 || max_depth > 100000) {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"maxDepth must be between 1 and 100000");
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

	/* Re-entrancy guard: re-calling __construct on an already-initialised parser
	 * would leak the previous buf/line_acc/stack/attributes. Reject explicitly. */
	if (intern->parser.buf.s != NULL) {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"Resp3\\Parser is already constructed; create a new instance or call reset()");
		RETURN_THROWS();
	}

	resp3_parser_init(&intern->parser, (size_t) max_depth, (int64_t) max_bulk, (int64_t) max_count);
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

/* Drive the state machine until a complete message lands in p->completed,
 * an error is hit (throws), or the buffer runs out (returns false). */
PHP_METHOD(Resp3_Parser, hasNext)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_t *p = &intern->parser;

	if (Z_TYPE(p->completed) != IS_UNDEF) {
		RETURN_TRUE;
	}

	char err[128] = {0};
	resp3_parse_result_t rc = resp3_parser_step(p, err, sizeof(err));

	if (rc == RESP3_PARSE_ERROR) {
		zend_throw_exception_ex(resp3_redis_exception_ce, 0,
			"RESP3 parse error: %s", err);
		RETURN_THROWS();
	}

	RETURN_BOOL(rc == RESP3_PARSE_COMPLETE);
}

PHP_METHOD(Resp3_Parser, next)
{
	ZEND_PARSE_PARAMETERS_NONE();

	resp3_parser_object *intern = Z_RESP3_PARSER_P(ZEND_THIS);
	resp3_parser_t *p = &intern->parser;

	/* If the caller didn't pre-check via hasNext(), drive the state machine ourselves. */
	if (Z_TYPE(p->completed) == IS_UNDEF) {
		char err[128] = {0};
		resp3_parse_result_t rc = resp3_parser_step(p, err, sizeof(err));

		if (rc == RESP3_PARSE_ERROR) {
			zend_throw_exception_ex(resp3_redis_exception_ce, 0,
				"RESP3 parse error: %s", err);
			RETURN_THROWS();
		}
		if (rc == RESP3_PARSE_NEED_MORE) {
			zend_throw_exception_ex(resp3_redis_exception_ce, 0,
				"next() called with no complete message available; check hasNext() first");
			RETURN_THROWS();
		}
	}

	/* COMPLETE — hand the parsed value back to userland and clear the slot. */
	zval out;
	ZVAL_COPY_VALUE(&out, &p->completed);
	ZVAL_UNDEF(&p->completed);

	/* For top-level error replies, wrap in Resp3\RedisException so userland can
	 * distinguish errors from arbitrary strings. */
	if (p->completed_type == '-' || p->completed_type == '!') {
		zval ex;
		object_init_ex(&ex, resp3_redis_exception_ce);
		zend_update_property_string(resp3_redis_exception_ce, Z_OBJ(ex),
			"message", sizeof("message") - 1,
			Z_TYPE(out) == IS_STRING ? Z_STRVAL(out) : "");
		zval_ptr_dtor(&out);
		RETURN_COPY_VALUE(&ex);
	}

	/* Verbatim string: split "xxx:payload" into type + value and wrap.
	 * The wire format is `=<len>\r\n<3-char prefix>:<payload>\r\n`. The type
	 * prefix is server-supplied untrusted input; we only accept 3 ASCII alnum
	 * chars. Anything else falls back to type="" with the full payload in value
	 * so consumers can still see the bytes without taking unsafe input as a tag. */
	if (p->completed_type == '=' && Z_TYPE(out) == IS_STRING) {
		zend_string *s = Z_STR(out);
		const char *raw = ZSTR_VAL(s);
		size_t      len = ZSTR_LEN(s);

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
			: zend_string_init("", 0, 0);
		zend_string *value_s = valid_prefix
			? zend_string_init(raw + 4, len - 4, 0)
			: zend_string_copy(s);

		zval vs;
		object_init_ex(&vs, resp3_verbatim_string_ce);
		zend_update_property_str(resp3_verbatim_string_ce, Z_OBJ(vs),
			"type", sizeof("type") - 1, type_s);
		zend_update_property_str(resp3_verbatim_string_ce, Z_OBJ(vs),
			"value", sizeof("value") - 1, value_s);

		zend_string_release(type_s);
		zend_string_release(value_s);
		zval_ptr_dtor(&out);
		RETURN_COPY_VALUE(&vs);
	}

	/* Push: wrap the array payload so consumers can route on instanceof. */
	if (p->completed_type == '>' && Z_TYPE(out) == IS_ARRAY) {
		zval pm;
		object_init_ex(&pm, resp3_push_message_ce);
		zend_update_property(resp3_push_message_ce, Z_OBJ(pm),
			"payload", sizeof("payload") - 1, &out);
		zval_ptr_dtor(&out);
		RETURN_COPY_VALUE(&pm);
	}

	RETURN_COPY_VALUE(&out);
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
