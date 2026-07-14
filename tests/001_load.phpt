--TEST--
Extension loads and reports version
--EXTENSIONS--
resp3
--FILE--
<?php
// Version-agnostic on purpose: a hardcoded string broke on every release
// bump. Check the function agrees with the module entry and looks like
// a semver triple instead.
var_dump(extension_loaded('resp3'));
var_dump(resp3_version() === phpversion('resp3'));
var_dump(preg_match('/^\d+\.\d+\.\d+$/', resp3_version()) === 1);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
