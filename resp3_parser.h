/*
  +----------------------------------------------------------------------+
  | php-resp3 — RESP3 wire-protocol parser                               |
  +----------------------------------------------------------------------+
  | Author: Christoph Kempen <christoph@downsized.nl>                    |
  +----------------------------------------------------------------------+
*/

#ifndef RESP3_PARSER_H
#define RESP3_PARSER_H

#include "php.h"
#include "zend_smart_str.h"

/* RESP3 wire type bytes. RESP2 subset: + - : $ * */
#define RESP3_TYPE_SIMPLE_STRING   '+'
#define RESP3_TYPE_ERROR           '-'
#define RESP3_TYPE_INTEGER         ':'
#define RESP3_TYPE_BULK_STRING     '$'
#define RESP3_TYPE_ARRAY           '*'
/* RESP3 additions */
#define RESP3_TYPE_NULL            '_'
#define RESP3_TYPE_DOUBLE          ','
#define RESP3_TYPE_BOOLEAN         '#'
#define RESP3_TYPE_BIG_NUMBER      '('
#define RESP3_TYPE_BLOB_ERROR      '!'
#define RESP3_TYPE_VERBATIM_STRING '='
#define RESP3_TYPE_MAP             '%'
#define RESP3_TYPE_SET             '~'
#define RESP3_TYPE_ATTRIBUTE       '|'
#define RESP3_TYPE_PUSH            '>'

typedef enum {
	RESP3_S_TYPE,        /* expect type byte */
	RESP3_S_LEN,         /* reading length digits (for $, *, %, ~, =, !, >, |) */
	RESP3_S_LEN_LF,      /* expect \n after \r in length line */
	RESP3_S_BULK_DATA,   /* reading N bytes of bulk payload */
	RESP3_S_BULK_CR,     /* expect \r after bulk payload */
	RESP3_S_BULK_LF,     /* expect \n */
	RESP3_S_LINE,        /* reading inline line (+, -, :, ,, #, (, _) */
	RESP3_S_LINE_LF      /* expect \n */
} resp3_state_t;

/* A frame on the parser stack. One frame per nested aggregate (array/map/set/push/attribute). */
typedef struct {
	char    type;             /* RESP3 type byte for this frame: *, %, ~, >, | */
	int64_t count;            /* remaining children */
	zval    accum;            /* accumulator (PHP array being filled) */
	int     map_key_pending;  /* 1 if next child is a map key, 0 if value */
	zend_string *pending_key; /* held key for map until value parsed */
} resp3_frame_t;

/* Hard caps to keep adversarial wire input from causing OOM or integer overflow.
 * Both are user-configurable via Resp3\Parser::__construct(). */
#define RESP3_DEFAULT_MAX_BULK   (((int64_t) 512) * 1024 * 1024)   /* 512 MiB per bulk */
#define RESP3_DEFAULT_MAX_COUNT  ((int64_t) 1000000)               /* 1M elements per aggregate */
#define RESP3_HARD_DIGIT_LIMIT   19                                /* int64_t max decimal digits */
#define RESP3_MAX_INLINE_LINE    65536                             /* cap on +/-/:/,/#/(/_ payload */

typedef struct {
	smart_str        buf;          /* rolling input buffer */
	size_t           pos;          /* consumed offset into buf */
	resp3_state_t    state;
	char             cur_type;     /* type byte of frame currently being parsed */
	int64_t          int_acc;      /* length / integer accumulator */
	int              int_neg;      /* sign for parsing */
	int              int_digits;   /* digits seen in current length parse (DoS guard) */
	int64_t          bulk_len;     /* declared bulk length */
	int64_t          bulk_read;    /* bytes already read into line_acc */
	smart_str        line_acc;     /* line accumulator for + - : , # ( _ and bulk payload */
	resp3_frame_t   *stack;        /* explicit parser stack */
	size_t           depth;
	size_t           cap;
	size_t           max_depth;    /* default 100 */
	int64_t          max_bulk;     /* per-bulk byte cap (default 512 MiB) */
	int64_t          max_count;    /* per-aggregate element cap (default 1M) */
	zval             attributes;   /* last-seen attribute payload (IS_NULL if none) */
	zval             completed;    /* completed top-level message (IS_UNDEF until ready) */
	char             completed_type; /* type byte of the last top-level completion */
} resp3_parser_t;

/* Parse result codes returned by resp3_parser_step. */
typedef enum {
	RESP3_PARSE_NEED_MORE = 0,  /* not enough bytes — call feed() and retry */
	RESP3_PARSE_COMPLETE  = 1,  /* a complete message landed in p->completed */
	RESP3_PARSE_ERROR     = -1  /* protocol violation */
} resp3_parse_result_t;

void resp3_parser_init(resp3_parser_t *p, size_t max_depth, int64_t max_bulk, int64_t max_count);
void resp3_parser_dtor(resp3_parser_t *p);
void resp3_parser_reset(resp3_parser_t *p);
void resp3_parser_feed(resp3_parser_t *p, const char *bytes, size_t len);
resp3_parse_result_t resp3_parser_step(resp3_parser_t *p, char *err, size_t err_len);

#endif /* RESP3_PARSER_H */
