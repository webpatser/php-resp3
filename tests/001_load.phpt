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
string(5) "0.1.0"
