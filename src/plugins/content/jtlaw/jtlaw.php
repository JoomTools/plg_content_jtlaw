<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Content.Jtlaw
 *
 * @author       Guido De Gobbis <support@joomtools.de>
 * @copyright    2018 JoomTools.de - All rights reserved.
 * @license      GNU General Public License version 3 or later
 **/

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

JLoader::import('joomla.filesystem.file');
JLoader::import('joomla.filesystem.folder');

/**
 * Class plgContentJtlaw
 *
 * Insert and cache HTML files from Your own Server
 *
 * @package     Joomla.Plugin
 * @subpackage  Content.jtlaw
 * @since       1.0.0
 */
class PlgContentJtlaw extends JPlugin
{
	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var     boolean
	 * @since   1.0.5
	 */
	protected $autoloadLanguage = true;
	/**
	 * Global application object
	 *
	 * @var     JApplication
	 * @since   1.0.5
	 */
	protected $app;
	/**
	 * Revised plugin parameters
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $plgParams = [];
	/**
	 * Collection point for error messages
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $message = [];
	/**
	 * Replacement for plugin call
	 *
	 * @var     string
	 * @since   1.0.0
	 */
	protected $buffer = null;

	/**
	 * onContentPrepare
	 *
	 * @param   string   $context  The context of the content being passed to the plugin.
	 * @param   object   $article  The article object.  Note $article->text is also available
	 * @param   mixed    $params   The article params
	 * @param   integer  $page     The 'page' number
	 *
	 * @return   void
	 * @since    1.0.0
	 */
	public function onContentPrepare($context, &$article, &$params, $page = 0)
	{
		// Don't run in administration Panel or when the content is being indexed
		if (strpos($article->text, '{jtlaw ') === false
			|| $this->app->isClient('administrator') === true
			|| $context == 'com_finder.indexer'
			|| $this->app->input->getCmd('layout') == 'edit')
		{
			return;
		}

		$cachePath                    = JPATH_PLUGINS . '/content/jtlaw/cache';
		$cacheOnOff                   = filter_var($this->params->get('cache', 1), FILTER_VALIDATE_BOOLEAN);
		$this->plgParams['server']    = rtrim($this->params->get('server'), '\\/');
		$this->plgParams['cachetime'] = (int) $this->params->get('cachetime', 1440) * 60;

		if ($this->plgParams['server'] == '')
		{
			$this->message['warning'][] = Text::_('PLG_CONTENT_JTLAW_WARNING_NO_SERVER');
		}

		if ($this->plgParams['cachetime'] < '600')
		{
			$this->plgParams['cachetime'] = '600';
		}

		if ($cacheOnOff === false)
		{
			$this->plgParams['cachetime'] = '0';
		}

		if (!JFolder::exists($cachePath))
		{
			JFolder::create($cachePath);
		}

		$plgCalls = $this->getPlgCalls($article->text);

		foreach ($plgCalls as $plgCall)
		{
			$fileName  = strtolower($plgCall[3]) . '.html';
			$cacheFile = $cachePath . '/' . $fileName;

			if ($useCacheFile = JFile::exists($cacheFile))
			{
				$useCacheFile = $this->getFileTime($cacheFile);
			}

			$this->setBuffer($cacheFile, $useCacheFile);
			$article->text = str_replace($plgCall[0], $this->buffer, $article->text);
			$this->buffer  = null;
		}

		if (JDEBUG && !empty($this->message))
		{
			foreach ($this->message as $type => $msgs)
			{
				if ($type == 'error')
				{
					$msgs[] = Text::_('PLG_CONTENT_JTLAW_ERROR_CHECKLIST');
				}

				$msg = implode('<br />', $msgs);
				$this->app->enqueueMessage($msg, $type);
			}
		}
	}

	/**
	 * Find all plugin call's in $text and return them as array
	 *
	 * @param   string  $text  Text with plugin call's
	 *
	 * @return   array  All matches found in $text
	 * @since    1.0.0
	 */
	protected function getPlgCalls($text)
	{
		$regex = '@(<(\w*+)[^>]*>|){jtlaw\s(.*)}(</\\2>|)@siU';
		$p1    = preg_match_all($regex, $text, $matches, PREG_SET_ORDER);

		if ($p1)
		{
			foreach ($matches as $key => $match)
			{
				$closeTag = ($match[2] != '') ? strpos($match[4], $match[2]) : true;

				if (!$closeTag)
				{
					$matches[$key][0] = str_replace($match[1], '', $match[0]);
				}
			}

			return $matches;
		}

		return array();
	}

	/**
	 * Check to see if the cache file is up to date
	 *
	 * @param   string  $file  Filename with absolute path
	 *
	 * @return   bool  true if cached file is up to date
	 * @since    1.0.0
	 */
	protected function getFileTime($file)
	{
		$time      = time();
		$cacheTime = $this->plgParams['cachetime'];
		$fileTime  = filemtime($file);

		$control = $time - $fileTime;

		if ($control >= $cacheTime)
		{
			return false;
		}

		return true;
	}

	/**
	 * Load HTML file from Server or get cached file
	 *
	 * @param   string $cacheFile    Filename with absolute path
	 * @param   bool   $useCacheFile @see PlgContentJtlaw->getFileTime($file)
	 *
	 * @return   bool  true if buffer is set else false
	 * @since    1.0.0
	 */
	protected function setBuffer($cacheFile, $useCacheFile = false)
	{
		$server   = $this->plgParams['server'];
		$fileName = basename($cacheFile);

		if ($useCacheFile === false)
		{
			$http = JHttpFactory::getHttp();
			$data = $http->get($server . '/' . $fileName);

			if ($data->code >= 200 && $data->code < 400)
			{
				$result = preg_replace(array('@<br>@i'), array('<br />'), $data->body);

				JFile::delete($cacheFile);
				JFile::write($cacheFile, $result);

				$this->buffer = $result;

				return true;
			}

			if (JFile::exists($cacheFile))
			{
				$this->setBuffer($cacheFile, true);

				return true;
			}

			$this->message['error'][] = Text::sprintf(
				'PLG_CONTENT_JTLAW_ERROR_NO_CACHE_SERVER', $fileName, $data->code
			);

			return false;
		}

		$this->buffer = @file_get_contents($cacheFile);

		return true;
	}
}
