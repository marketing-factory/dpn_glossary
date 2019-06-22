<?php
declare(strict_types=1);

namespace Featdd\DpnGlossary\Hook;

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

use Featdd\DpnGlossary\Service\ParserService;
use Featdd\DpnGlossary\Utility\ObjectUtility;
use Featdd\HtmlTermWrapper\Exception\ParserException;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * @package DpnGlossary
 * @subpackage Hook
 */
class ContentPostProcHook
{
    use LoggerAwareTrait;

    /**
     * @var \Featdd\DpnGlossary\Service\ParserService
     */
    protected $parserService;

    public function __construct()
    {
        $this->parserService = ObjectUtility::makeInstance(ParserService::class);
    }

    /**
     * @param array $params
     * @param \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $typoScriptFrontendController
     */
    public function main(array &$params, TypoScriptFrontendController $typoScriptFrontendController): void
    {
        try {
            $typoScriptFrontendController->content = $this->parserService->pageParser(
                $typoScriptFrontendController->content
            );
        } catch (ParserException $exception) {
            $this->logger->error('Error when parsing HTML for terms: "' . $exception->getMessage() . '"');
        }
    }
}
