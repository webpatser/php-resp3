--TEST--
Parser cannot be created without its constructor; serialize() throws
--EXTENSIONS--
resp3
--FILE--
<?php
try {
    (new ReflectionClass(Resp3\Parser::class))->newInstanceWithoutConstructor();
    echo "FAIL\n";
} catch (ReflectionException $e) {
    echo "guarded\n";
}
try {
    serialize(new Resp3\Parser());
    echo "FAIL\n";
} catch (\Exception $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
guarded
Exception
