/*
  +----------------------------------------------------------------------+
  | php-resp3 — RESP3 wire-protocol parser                               |
  +----------------------------------------------------------------------+
  | Author: Christoph Kempen <christoph@downsized.nl>                    |
  +----------------------------------------------------------------------+
*/

#ifndef PHP_RESP3_H
#define PHP_RESP3_H

extern zend_module_entry resp3_module_entry;
#define phpext_resp3_ptr &resp3_module_entry

#define PHP_RESP3_VERSION "0.1.0"

#if defined(ZTS) && defined(COMPILE_DL_RESP3)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif /* PHP_RESP3_H */
