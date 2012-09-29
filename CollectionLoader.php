<?php
namespace lib\loader;

/**
 * Moss class collection loader
 * Merges classes/interfaces from passed directories into one big (even huge) file
 *
 * @throws \RuntimeException
 * @package Moss Core
 * @author  Michal Wachowski <wachowski.michal@gmail.com>
 */
class CollectionLoader {

	protected $paths;
	protected $cache;
	protected $reload;
	protected $stripComments;

	protected $declared;
	protected $classes;

	/**
	 * Constructor
	 *
	 * @param string $cache         path to output file
	 * @param bool   $reload        set true, to reload/rebuild cache file
	 * @param bool   $stripComments if true, all comments will be removed
	 */
	public function __construct($cache, $reload = false, $stripComments = true) {
		$this->cache = (string) $cache;
		$this->reload = (bool) $reload;
		$this->stripComments = (bool) $stripComments;
		$this->declared = array_merge(get_declared_classes(), get_declared_interfaces());
	}

	/**
	 * Adds path (or paths if array passed) to gather classes from
	 *
	 * @param string|array $path
	 */
	public function addPath($path) {
		if(!is_array($path)) {
			$this->addPathToList($path);
			return;
		}

		foreach($path as $node) {
			$this->addPathToList($node);
		}
	}

	/**
	 * Adds single path entry to loader.
	 *
	 * @param string $path
	 */
	protected function addPathToList($path) {
		$this->paths[$path] = realpath(rtrim('/', $path).'/');
	}

	/**
	 * Collection loader handler
	 * If cache file exists - loads it
	 * If not - gathers classes from added paths and merges them into one file
	 */
	public function handler() {
		if(!$this->reload && is_file($this->cache)) {
			require $this->cache;
			return;
		}

		$this->gatherClasses();
		$conditionalLoader = $this->sortClassesDependency($this->classes);
		$this->buildNamespacedArray();

		$content = null;
		foreach($this->classes as $namespace => $classes) {
			$used = array();

			$content .= sprintf("\nnamespace %s {\n", $namespace !== '.' ? $namespace : null);

			foreach($classes as $class) {
				$Ref = new \ReflectionClass($class);

				$c = file_get_contents($Ref->getFileName());

				if($this->stripComments) {
					$c = $this->stripComments($c);
				}

				$c = $this->fixNamespaceDeclarations($c);
				$c = $this->fixUseDeclarations($c, $namespace, $used);
				$c = preg_replace(array('/^\s*<\?php/', '/\?>\s*$/'), '', $c);

				$c = trim($c);
				if(!empty($conditionalLoader[$class])) {
					$c = $this->addConditionalLoad($c, $class);
				}

				$content .= $c;
			}

			$content .= "\n}\n\n";
		}

		$content = "<?php\n" . $content;

		$this->writeCacheFile($this->cache, $content);
	}

	/**
	 * Gathers parent classes recursively and adds them to class dependencies
	 *
	 * @param \ReflectionClass $Ref
	 * @param array            $dependencies
	 */
	protected function gatherParentClasses(\ReflectionClass $Ref, &$dependencies) {
		$node = $Ref;
		while($parent = $node->getParentClass()) {
			if($parent->isInternal() || in_array($parent->getName(), $this->declared) || in_array($parent->getName(), $dependencies)) {
				break;
			}

			$dependencies[] = $parent->getName();

			$this->gatherInterfaceClasses($parent, $dependencies);

			$node = $parent;
		}
	}

	/**
	 * Gathers interfaces and adds them to class dependencies
	 *
	 * @param \ReflectionClass $Ref
	 * @param array            $dependencies
	 */
	protected function gatherInterfaceClasses(\ReflectionClass $Ref, &$dependencies) {
		if($interfaces = $Ref->getInterfaces()) {
			foreach($interfaces as $interface) {
				if($interface->isInternal() || in_array($interface->getName(), $this->declared) || in_array($interface->getName(), $dependencies)) {
					continue;
				}

				$dependencies[] = $interface->getName();
			}
		}
	}

	/**
	 * Gathers use definitions and adds them to class dependencies
	 *
	 * @param \ReflectionClass $Ref
	 * @param array            $dependencies
	 */
	protected function gatherUseClasses(\ReflectionClass $Ref, &$dependencies) {
		$source = file_get_contents($Ref->getFileName());
		preg_match_all('/^use[ \n]*([^;]+);/im', $source, $matches, PREG_SET_ORDER);

		if(empty($matches)) {
			return;
		}

		foreach($matches as $match) {
			$match[1] = explode(',', str_replace(array("\n", "\r"), null, $match[1]));

			foreach($match[1] as $class) {
				$class = ltrim($class, '\\');

				if(in_array($class, $this->declared) || in_array($class, $dependencies)) {
					continue;
				}

				$dependencies[] = $class;
			}
		}
	}

	/**
	 * Sorts gathered classes according to their namespace and dependencies
	 * Returns array containing classes that could not be correctly sorted and may require conditional loading
	 *
	 * @return array
	 */
	protected function sortClassesDependency() {
		$keys = array_keys($this->classes);
		$iArr = $this->classes;
		$oArr = array();
		$lArr = array();

		while(!empty($keys)) {
			$key = array_shift($keys);
			$dependant = false;
			$lArr[$key] = isset($lArr[$key]) ? $lArr[$key] + 1 : 0;

			foreach($iArr[$key] as $dependency) {
				if(in_array($dependency, $keys)) {
					$dependant = true;
					break;
				}
			}

			if($dependant && $lArr[$key] < 3) {
				array_push($keys, $key);
				continue;
			}

			$oArr[$key] = $iArr[$key];

			unset($iArr[$key]);
			foreach($iArr as &$dependencies) {
				if(in_array($key, $dependencies)) {
					unset($dependencies[array_search($key, $dependencies)]);
				}

				unset($dependencies);
			}
		}

		$this->classes = $oArr;

		$oArr = array();
		foreach($lArr as $key => $value) {
			if($value < 3) {
				continue;
			}

			$oArr[$key] = $value;
		}

		return $oArr;
	}

	/**
	 * Reads classes and interfaces from directories
	 */
	protected function gatherClasses() {
		$this->classes = array();

		foreach($this->paths as $path) {
			$RecursiveIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

			foreach($RecursiveIterator as $item) {
				if(!$class = $this->identify($item)) {
					continue;
				}

				if(in_array($class, $this->declared)) {
					continue;
				}

				$dependencies = array();
				$Ref = new \ReflectionClass($class);

				$this->gatherParentClasses($Ref, $dependencies);
				$this->gatherInterfaceClasses($Ref, $dependencies);
				$this->gatherUseClasses($Ref, $dependencies);

				$this->classes[$class] = $dependencies;
			}
		}
	}

	/**
	 * Converts sorted array of class dependencies into namespaced array
	 * Each key is namespace and its values are classes from that namespace in preserved order
	 */
	protected function buildNamespacedArray() {
		$classes = array();
		foreach($this->classes as $class => $dependencies) {
			$namespace = dirname($class);
			if(!isset($classes[$namespace])) {
				$classes[$namespace] = array();
			}

			$classes[$namespace][] = $class;
		}

		$this->classes = $classes;
	}

	/**
	 * Identifies class in file
	 * Returns class or interface name or false if no definition found or file is not valid
	 *
	 * @param \SplFileInfo $file
	 *
	 * @return bool|string
	 */
	protected function identify(\SplFileInfo $file) {
		if(!$file->isFile()) {
			return false;
		}

		if(!preg_match('/^.*\.php$/', (string) $file)) {
			return false;
		}

		$content = file_get_contents($file->getPathname(), null, null, 0, 1024);

		preg_match_all('/^namespace (.+);/im', $content, $nsMatches);
		preg_match_all('/^(abstract )?(interface|class) ([^ \n{]+).*$/im', $content, $nameMatches);

		if(!isset($nameMatches[3][0]) || empty($nameMatches[3][0])) {
			return false;
		}

		if(empty($nsMatches[1][0])) {
			return $nameMatches[3][0];
		}

		return $nsMatches[1][0] . '\\' . $nameMatches[3][0];
	}

	/**
	 * Removes namespace declaration from class
	 *
	 * @param string $source
	 *
	 * @return string
	 */
	protected function fixNamespaceDeclarations($source) {
		$source = preg_replace('/^namespace [^;]+;/im', null, $source);
		return $source;
	}

	/**
	 * Fixes use declarations in class definition
	 *
	 * @param string $source
	 * @param string $namespace
	 * @param array  $used
	 *
	 * @return string
	 */
	protected function fixUseDeclarations($source, $namespace, &$used) {
		preg_match_all('/^use[ \n]*([^;]+);/im', $source, $matches, PREG_SET_ORDER);

		if(!isset($matches[0][0])) {
			return $source;
		}

		foreach($matches as $match) {
			$uses = array();
			$match[1] = explode(',', str_replace(array("\n", "\r"), null, $match[1]));
			foreach($match[1] as $node) {
				if(ltrim(dirname($node), '\\') == $namespace || in_array($node, $used)) {
					continue;
				}

				$used[] = $node;
				$uses[] = $node;
			}

			$source = str_replace($match[0], (empty($uses) ? null : 'use ' . implode(', ', $uses) . ';'), $source);
		}

		return $source;
	}

	/**
	 * Removes comments from class definition
	 *
	 * @param string $source
	 *
	 * @return string
	 */
	protected static function stripComments($source) {
		if(!function_exists('token_get_all')) {
			return $source;
		}

		$output = '';
		foreach(token_get_all($source) as $token) {
			if(is_string($token)) {
				$output .= $token;
			}
			elseif(!in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
				$output .= $token[1];
			}
		}

		$output = preg_replace(array('/\s+$/Sm', '/\n+/S'), "\n", $output);

		return $output;
	}

	/**
	 * Adds conditional load for class definition
	 * Class will be loaded only when its declaration is not present
	 *
	 * @param string $source
	 * @param string $class
	 *
	 * @return string
	 */
	protected function addConditionalLoad($source, $class) {
		preg_match_all('/^use[ \n]*([^;]+);/im', $source, $matches, PREG_SET_ORDER);

		if(!$matches) {
			return sprintf("\nif(!class_exists('\\%1\$s', false) && !interface_exists('\\%1\$s', false)) {\n%2\$s\n}\n", $class, $source);
		}

		$key = count($matches) - 1;
		$source = str_replace($matches[$key][0], $matches[$key][0] . sprintf("\nif(!class_exists('\\%1\$s', false) && !interface_exists('\\%1\$s', false)) {\n", $class), $source) . "\n}\n";

		return $source;
	}

	/**
	 * Creates cache directory from path to cache file
	 *
	 * @param string $path
	 *
	 * @throws \RuntimeException
	 */
	protected function makePath($path) {
		if(is_dir($path)) {
			return;
		}

		if(!mkdir($path, 0644, true)) {
			throw new \RuntimeException(sprintf('Unable to create cache dir %s', $path));
		}
	}

	/**
	 * Writes merged classes into set cache file
	 *
	 * @param string $file
	 * @param string $content
	 *
	 * @throws \RuntimeException
	 */
	protected function writeCacheFile($file, $content) {
		$path = dirname($file);
		if(!is_dir($path) && !mkdir($path, 0644, true)) {
			throw new \RuntimeException(sprintf('Unable to create cache dir %s', $path));
		}

		if(!file_put_contents($file, $content, LOCK_EX)) {
			throw new \RuntimeException(sprintf('Failed to write cache file "%s".', $file));
		}
	}
}