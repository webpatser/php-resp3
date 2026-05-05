---
name: Bug report
about: The parser produced something it shouldn't have, crashed, or hung.
title: ''
labels: bug
assignees: ''
---

## What happened

One or two sentences. What did you feed the parser, what did you expect,
what did you get?

## Wire bytes

A hex dump of the input. `xxd` output is fine. If the bytes came from a
running Redis or Valkey, capture them with `tools/capture_fixtures.sh`
or `socat` and attach the `.bin`.

```
00000000: 2b4f 4b0d 0a                              +OK..
```

## Reproducer

```php
$p = new Resp3\Parser();
$p->feed($bytes);
while ($p->hasNext()) {
    var_dump($p->next());
}
```

## Expected output

What `var_dump` should have printed.

## Actual output

What it actually printed, or the exception that was thrown, or "the PHP
process was killed by the OS".

## Environment

- Extension version: `php -d extension=./modules/resp3.so -r 'echo resp3_version();'`
- PHP version and SAPI: `php -v`
- Operating system and architecture: `uname -srm`
- Redis or Valkey version on the wire (if applicable)

## Anything else
