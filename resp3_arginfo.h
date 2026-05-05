/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 4b33cc9490b9ebcdb5f7eb3364a29d627dc70afc */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_resp3_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Resp3_Parser___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxDepth, IS_LONG, 0, "100")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxBulk, IS_LONG, 0, "536870912")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxAggregateCount, IS_LONG, 0, "1000000")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Resp3_Parser_feed, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, bytes, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Resp3_Parser_hasNext, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Resp3_Parser_next, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Resp3_Parser_reset, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Resp3_Parser_lastAttributes, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Resp3_VerbatimString___construct, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, type, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Resp3_PushMessage___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, payload, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(resp3_version);
ZEND_METHOD(Resp3_Parser, __construct);
ZEND_METHOD(Resp3_Parser, feed);
ZEND_METHOD(Resp3_Parser, hasNext);
ZEND_METHOD(Resp3_Parser, next);
ZEND_METHOD(Resp3_Parser, reset);
ZEND_METHOD(Resp3_Parser, lastAttributes);
ZEND_METHOD(Resp3_VerbatimString, __construct);
ZEND_METHOD(Resp3_PushMessage, __construct);

static const zend_function_entry ext_functions[] = {
	ZEND_FE(resp3_version, arginfo_resp3_version)
	ZEND_FE_END
};

static const zend_function_entry class_Resp3_Parser_methods[] = {
	ZEND_ME(Resp3_Parser, __construct, arginfo_class_Resp3_Parser___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Resp3_Parser, feed, arginfo_class_Resp3_Parser_feed, ZEND_ACC_PUBLIC)
	ZEND_ME(Resp3_Parser, hasNext, arginfo_class_Resp3_Parser_hasNext, ZEND_ACC_PUBLIC)
	ZEND_ME(Resp3_Parser, next, arginfo_class_Resp3_Parser_next, ZEND_ACC_PUBLIC)
	ZEND_ME(Resp3_Parser, reset, arginfo_class_Resp3_Parser_reset, ZEND_ACC_PUBLIC)
	ZEND_ME(Resp3_Parser, lastAttributes, arginfo_class_Resp3_Parser_lastAttributes, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static const zend_function_entry class_Resp3_VerbatimString_methods[] = {
	ZEND_ME(Resp3_VerbatimString, __construct, arginfo_class_Resp3_VerbatimString___construct, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static const zend_function_entry class_Resp3_PushMessage_methods[] = {
	ZEND_ME(Resp3_PushMessage, __construct, arginfo_class_Resp3_PushMessage___construct, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Resp3_Parser(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Resp3", "Parser", class_Resp3_Parser_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);

	return class_entry;
}

static zend_class_entry *register_class_Resp3_RedisException(zend_class_entry *class_entry_RuntimeException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Resp3", "RedisException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_RuntimeException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Resp3_VerbatimString(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Resp3", "VerbatimString", class_Resp3_VerbatimString_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);

	zval property_type_default_value;
	ZVAL_UNDEF(&property_type_default_value);
	zend_declare_typed_property(class_entry, ZSTR_KNOWN(ZEND_STR_TYPE), &property_type_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));

	zval property_value_default_value;
	ZVAL_UNDEF(&property_value_default_value);
	zend_declare_typed_property(class_entry, ZSTR_KNOWN(ZEND_STR_VALUE), &property_value_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));

	return class_entry;
}

static zend_class_entry *register_class_Resp3_PushMessage(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Resp3", "PushMessage", class_Resp3_PushMessage_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);

	zval property_payload_default_value;
	ZVAL_UNDEF(&property_payload_default_value);
	zend_string *property_payload_name = zend_string_init("payload", sizeof("payload") - 1, 1);
	zend_declare_typed_property(class_entry, property_payload_name, &property_payload_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_payload_name);

	return class_entry;
}
