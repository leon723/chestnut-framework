<?php
namespace Chestnut\View;

use Chestnut\Support\Cache;
use Chestnut\Support\File;
use Chestnut\View\Engine\Engine;
use Exception;
use FatalThrowableError;
use InvalidArgumentException;
use RuntimeException;

/**
 * @author Liyang Zhang <zhangliyang@zhangliyang.name>
 *
 * View
 */
class View {

	/**
	 * View Factory
	 * @var Chestnut\View\Factory
	 */
	protected $factory;

	/**
	 * View Data
	 * @var array
	 */
	protected $data = [];

	/**
	 * Global Data
	 * @var array
	 */
	protected $global = [];

	/**
	 * File Path
	 * @var string
	 */
	protected $path;

	/**
	 * Cache Path
	 * @var String
	 */
	protected $cachePath;

	/**
	 * View File Name
	 * @var string
	 */
	protected $filename;

	/**
	 * Template Engines
	 * @var array
	 */
	protected $engines = [
		'.nut.php' => 'view.engine.nut',
		'.php' => 'view.engine.nut',
	];

	/**
	 * Require Engine Name
	 * @var string
	 */
	protected $requireEngine;

	protected $layout;

	protected $sections = [];

	protected $sectionStack = [];

	public function __construct(Factory $factory) {
		$this->factory = $factory;

		$this->injectGlobalScope();
	}

	public function setEngine(Engine $engine) {
		$this->engine = $engine;
	}

	public function setFilename($filename) {
		$filename = join(explode('.', $filename), DIRECTORY_SEPARATOR);
		$this->filename = $filename;

		foreach ($this->engines as $extension => $engine) {
			if (file_exists($this->path . $filename . $extension)) {
				$this->filename = $filename . $extension;
				$this->requireEngine = $engine;
				break;
			}
		}
	}

	public function setPath($path) {
		$this->path = $path;
	}

	public function setCachePath($path) {
		$this->cachePath = $path;
	}

	public function getRequireEngine() {
		return $this->requireEngine;
	}

	public function injectGlobalScope() {
		if ($this->factory->hasGlobal()) {
			$this->global = $this->factory->getGlobal();
		} else {
			$this->global = [];
		}
	}

	public function isCacheable() {
		if (File::diffTime($this->path . $this->filename, $this->cachePath . md5($this->filename))) {
			return false;
		}

		return true;
	}

	public function hasEngine() {
		return isset($this->engine);
	}

	public function render() {
		if (!$this->isCacheable()) {

			try {
				$content = File::readFile($this->path . $this->filename);
			} catch (Exception $e) {
				throw new RuntimeException("View [{$this->filename}] not found");
			}

			$engine = $this->factory->resolveEngine($this->getRequireEngine());

			$content = $engine->render($content);

			$this->cache($content);
		}

		return $this->renderContent();
	}

	protected function cache($content) {
		Cache::write('views', md5($this->filename), $content);
	}

	public function renderContent() {
		$obLevel = ob_get_level();

		ob_start();

		$properties = array_merge($this->global, $this->data);

		extract($properties);

		try {
			include $this->cachePath . md5($this->filename);
		} catch (Exception $e) {
			$this->handleViewException($e, $obLevel);
		} catch (Throwable $e) {
			$this->handleViewException(new FatalThrowableError($e), $obLevel);
		}

		return ltrim(ob_get_clean());
	}

	public function handleViewException($e, $obLevel) {
		while (ob_get_level() > $obLevel) {
			ob_end_clean();
		}

		throw $e;
	}

	public function layout($layout) {
		$this->layout = $layout;
	}

	public function sectionStart($section) {
		if (ob_start()) {
			$this->sectionStack[] = $section;
		}
	}

	public function sectionEnd() {
		if (empty($this->sectionStack)) {
			throw new InvalidArgumentException('Cannot end a section without first starting one.');
		}

		$section = array_pop($this->sectionStack);

		$this->sections[$section] = ob_get_clean();
	}

	public function showSection() {
		$parent = ob_get_clean();

		$section = array_pop($this->sectionStack);

		$content = isset($this->sections[$section]) ? $this->sections[$section] : '';

		echo $parent . $content;
	}

	public function injectSection($section) {
		$this->sections = $section;
	}

	public function renderLayout($data) {
		$view = $this->factory->make($this->layout, $data);
		$view->injectSection($this->sections);

		echo $view->render();
	}

	public function data($data) {
		if (empty($this->data)) {
			$this->data = $data;
		} else {
			foreach ($data as $key => $item) {
				$this->data[$key] = $item;
			}
		}

		return $this;
	}

	public function getData() {
		return $this->data;
	}

	public function addData($key, $value) {
		$this->data[$key] = $value;
	}

	public function __call($key, $value) {
		$this->addData($key, $value[0]);
		return $this;
	}
}
