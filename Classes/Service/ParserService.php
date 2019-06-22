<?php
declare(strict_types=1);

namespace Featdd\DpnGlossary\Service;

/***
 *
 * This file is part of the "dreipunktnull Glossar" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2019 Daniel Dorndorf <dorndorf@featdd.de>
 *
 ***/

use Closure;
use Featdd\DpnGlossary\Domain\Model\Term;
use Featdd\DpnGlossary\Domain\Repository\TermRepository;
use Featdd\DpnGlossary\Utility\ObjectUtility;
use Featdd\HtmlTermWrapper\HtmlTermWrapper;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * @package DpnGlossary
 * @subpackage Service
 */
class ParserService implements SingletonInterface
{
    public const REGEX_DELIMITER = '/';
    public const ALWAYS_IGNORE_PARENT_TAGS = [
        'a',
        'script',
    ];

    /**
     * @var ContentObjectRenderer
     */
    protected $contentObjectRenderer;

    /**
     * @var \Featdd\HtmlTermWrapper\HtmlTermWrapper
     */
    protected $htmlTermWrapper;

    /**
     * @var array
     */
    protected $terms = [];

    /**
     * @var array
     */
    protected $typoScriptConfiguration = [];

    /**
     * @var array
     */
    protected $settings = [];

    /**
     * Boots up:
     *  - configuration manager for TypoScript settings
     *  - contentObjectRenderer for generating links etc.
     *  - termRepository to get the Terms
     *
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function __construct()
    {
        // Get Configuration Manager
        /** @var \TYPO3\CMS\Extbase\Configuration\ConfigurationManager $configurationManager */
        $configurationManager = ObjectUtility::makeInstance(ConfigurationManager::class);
        // Inject Content Object Renderer
        $this->contentObjectRenderer = ObjectUtility::makeInstance(ContentObjectRenderer::class);
        // Get Query Settings
        /** @var \TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings */
        $querySettings = ObjectUtility::makeInstance(QuerySettingsInterface::class);
        // Get termRepository
        /** @var \Featdd\DpnGlossary\Domain\Repository\TermRepository $termRepository */
        $termRepository = ObjectUtility::makeInstance(TermRepository::class);
        // Get Typoscript Configuration
        $this->typoScriptConfiguration = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
        // Reduce TS config to plugin
        $this->typoScriptConfiguration = $this->typoScriptConfiguration['plugin.']['tx_dpnglossary.'];

        if (null !== $this->typoScriptConfiguration && 0 < \count($this->typoScriptConfiguration)) {
            // Save extension settings without ts dots
            $this->settings = GeneralUtility::removeDotsFromTS($this->typoScriptConfiguration['settings.']);
            // Set StoragePid in the query settings object
            $querySettings->setStoragePageIds(
                GeneralUtility::trimExplode(',', $this->typoScriptConfiguration['persistence.']['storagePid'])
            );

            try {
                /** @var \TYPO3\CMS\Core\Context\Context $context */
                $context = ObjectUtility::makeInstance(Context::class);
                $sysLanguageUid = $context->getPropertyFromAspect('language', 'id');
            } catch (AspectNotFoundException $exception) {
                $sysLanguageUid = 0;
            }

            // Set current language uid
            $querySettings->setLanguageUid($sysLanguageUid);
            // Set query to respect the language uid
            $querySettings->setRespectSysLanguage(true);
            // Assign query settings object to repository
            $termRepository->setDefaultQuerySettings($querySettings);

            //Find all terms
            if (false === (bool) $this->settings['useCachingFramework']) {
                $terms = $termRepository->findByNameLength(true);
            } else {
                /** @var \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface $cacheManager */
                $cache = ObjectUtility::makeInstance(CacheManager::class)->getCache('dpnglossary_termscache');
                $cacheIdentifier = sha1('termsByNameLength' . $querySettings->getLanguageUid());
                $terms = $cache->get($cacheIdentifier);

                // If $terms is null, it hasn't been cached. Calculate the value and store it in the cache:
                if ($terms === false) {
                    $terms = $termRepository->findByNameLength(true);
                    // Save value in cache
                    $cache->set($cacheIdentifier, $terms, ['dpnglossary_termscache']);
                }
            }

            $this->terms = $terms;
        }

        $this->htmlTermWrapper = new HtmlTermWrapper(...$this->terms);
    }

    /**
     * parse html for terms and return the parsed html
     * or false if parsers has to be aborted
     *
     * @param string $html
     * @return string
     * @throws \Featdd\HtmlTermWrapper\Exception\ParserException
     */
    public function pageParser(string $html): string
    {
        // extract Pids which should be parsed
        $parsingPids = GeneralUtility::intExplode(',', $this->settings['parsingPids'], true);
        // extract Pids which should NOT be parsed
        $excludePids = GeneralUtility::intExplode(',', $this->settings['parsingExcludePidList'], true);
        // Get Tags which content should be parsed
        $parsingTags = GeneralUtility::trimExplode(',', $this->settings['parsingTags'], true);

        $currentPageId = (int) $GLOBALS['TSFE']->id;
        $currentPageType = (int) $GLOBALS['TSFE']->type;

        // Abort parser...
        if (
            // Parser disabled
            true === (bool) $this->settings['disableParser'] ||
            // Pagetype not 0
            0 !== $currentPageType ||
            // no tags to parse given
            0 === \count($parsingTags) ||
            // no terms have been found
            0 === \count($this->terms) ||
            // no config is given
            0 === \count($this->typoScriptConfiguration) ||
            // page is excluded
            true === \in_array($currentPageId, $excludePids, true) ||
            (
                // parsingPids doesn't contain 0 and...
                false === \in_array(0, $parsingPids, true) &&
                // page is not whitelisted
                false === \in_array($currentPageId, $parsingPids, true)
            )
        ) {
            return $html;
        }

        $this->htmlTermWrapper->setParsingTags($parsingTags);
        $this->htmlTermWrapper::$alwaysIgnoreParentTags = static::ALWAYS_IGNORE_PARENT_TAGS;
        $this->htmlTermWrapper::$forbiddenParentTags = GeneralUtility::trimExplode(',', $this->settings['forbiddenParentTags'], true);
        $this->htmlTermWrapper::$forbiddenParsingTagClasses = GeneralUtility::trimExplode(',', $this->settings['forbiddenParsingTagClasses'], true);

        return $this->htmlTermWrapper->parseHtml(
            $html,
            Closure::fromCallable([$this, 'termWrapper'])
        );
    }

    /**
     * Renders the wrapped term using the plugin settings
     *
     * @param \Featdd\DpnGlossary\Domain\Model\Term
     * @return string
     * @throws \UnexpectedValueException
     */
    protected function termWrapper(Term $term): string
    {
        // get content object type
        $contentObjectType = $this->typoScriptConfiguration['settings.']['termWraps'];
        // get term wrapping settings
        $wrapSettings = $this->typoScriptConfiguration['settings.']['termWraps.'];
        // pass term data to the cObject pseudo constructor
        $this->contentObjectRenderer->start(
            $term->__toArray(),
            Term::TABLE
        );

        // return the wrapped term
        return $this->contentObjectRenderer->cObjGetSingle($contentObjectType, $wrapSettings);
    }
}
