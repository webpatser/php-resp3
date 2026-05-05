---
name: Feature request
about: Something the parser doesn't do that you wish it did.
title: ''
labels: enhancement
assignees: ''
---

## Use case

Describe the workload. What kind of Redis or Valkey traffic, what kind
of application code wants the new behaviour. A real example beats an
abstract description.

## Current workaround

What do you do today to get around the missing feature? If there is no
workaround, say so.

## Proposed API

If you have a sketch of how the feature would look in PHP, drop it
here. If not, leave this blank and the maintainer will start the design
discussion.

```php
// Optional pseudocode
$p = new Resp3\Parser();
$p->onPushMessage(function (Resp3\PushMessage $m) { ... });
```

## Why this should live in the extension

Parser scope is deliberately small (bytes in, PHP values out). Features
that fit naturally in PHP user space, an adapter layer, or a separate
package usually stay there. If the proposal needs C-level work or
cannot reasonably live outside the parser, say why.

## Anything else
