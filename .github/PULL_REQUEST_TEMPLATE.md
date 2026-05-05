# Pull request

## What this changes

One paragraph. What did you do, why does it belong in this repo.

## Checklist

Tick what applies. If something doesn't, say why in the section below.

- [ ] `phpize && ./configure --enable-resp3 && make` succeeds locally.
- [ ] `make test TESTS="tests/"` is fully green.
- [ ] If the change touches the userland API, `resp3.stub.php` is
      updated and `resp3_arginfo.h` is regenerated. The PHP 8.3
      compatibility hand tweak (`zend_register_internal_class_ex`
      rewrite, see `CONTRIBUTING.md`) is re-applied.
- [ ] If the change touches the state machine, `ARCHITECTURE.md`
      reflects the new shape.
- [ ] If the change shifts performance, `bench/results/SUMMARY.md`
      and `BENCHMARKS.md` are refreshed (or you note the gap).
- [ ] Tests cover the new code paths, including any new error path.
- [ ] No AI attribution in commit messages (`Co-Authored-By: Claude`,
      `Generated with`, etc).

## Notes for the reviewer

Anything you want a second pair of eyes on. Trade-offs you considered.
Things you're not sure about.

## Linked issue

Closes #...
