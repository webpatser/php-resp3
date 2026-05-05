PHP_ARG_ENABLE([resp3],
  [whether to enable resp3 support],
  [AS_HELP_STRING([--enable-resp3],
    [Enable resp3 support])],
  [no])

if test "$PHP_RESP3" != "no"; then
  AC_DEFINE(HAVE_RESP3, 1, [ Have resp3 support ])
  PHP_NEW_EXTENSION(resp3, resp3.c resp3_parser.c, $ext_shared)
fi
