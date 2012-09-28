# CollectionLoader

Merges classes and interfaces declaration into one huge file.
Compatible with PSR-0

For licence details see Licence.md

## Usage

	$CollectionLoader = new \lib\loader\CollectionLoader('path_to_merged_cache.php');
    $CollectionLoader->addPath('./directory/to/read/from/');
    $CollectionLoader->handler();

In constructor, first argument is path to cache file, where merged classes will be written.
Second argument forces rebuild of cache file (default: `false`).
Third decides if comments should be stripped from class definitions (default: `true`)

`CollectionLoader::addPath()` method, adds directory to loader from witch class/interface definitions will be read.

`CollectionLoader::handler()` handles reading cache file, if cache file does not exists - will be build.

## Notice

In some cases, especialy when complex dependencies occour, due PHP error CollectionLoader should be supported by oridinary autoloading class.
Eg.

	namespace bar {
        class Bar extends \foo\Foo {}
    }

    namespace foo {
       class Foo implements \yada\Yada {}
    }

    namespace yada {
       interface Yada {}
    }

This construction will throw error - PHP can not find \foo\Foo class.
There are only two solutions - remove \yada\Yada dependency or use external autoloader.
