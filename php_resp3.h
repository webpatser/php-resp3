/*
  +----------------------------------------------------------------------+
  | php-resp3: RESP3 wire-protocol parser                                |
  +----------------------------------------------------------------------+
  | Author: Christoph Kempen <christoph@downsized.nl>                    |
  +----------------------------------------------------------------------+
*/

#ifndef PHP_RESP3_H
#define PHP_RESP3_H

extern zend_module_entry resp3_module_entry;
#define phpext_resp3_ptr &resp3_module_entry

#include "php.h"

#define PHP_RESP3_VERSION "0.2.0"

/* Default nesting cap and the hard ceiling for Resp3\Parser's maxDepth.
 * Destroying a reply nested much deeper than the ceiling recurses far enough
 * to risk the C stack. */
#ifndef RESP3_DEFAULT_MAX_DEPTH
# define RESP3_DEFAULT_MAX_DEPTH 100
#endif
#define RESP3_MAX_DEPTH_CEILING 10000

/* Replace *val in place with its userland wrapper: `-` and `!` strings become
 * Resp3\RedisException (binary-safe message, `prefix` set to the leading
 * [A-Z0-9_] token or ""), `=` strings become Resp3\VerbatimString, and `>`
 * arrays become Resp3\PushMessage. Any other type or value is left as is.
 * Implemented in resp3.c, called by resp3_parser.c. */
void resp3_wrap_reply(char type, zval *val);

#if defined(ZTS) && defined(COMPILE_DL_RESP3)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif /* PHP_RESP3_H */
