--TEST--
Extension loads and reports version
--EXTENSIONS--
resp3
--FILE--
<?php
var_dump(extension_loaded('resp3'));
var_dump(resp3_version());
?>
--EXPECT--
bool(true)
string(9) "0.1.1-rc1"
