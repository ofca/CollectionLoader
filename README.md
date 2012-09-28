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