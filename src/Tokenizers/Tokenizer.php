<?php
/**
 * The base tokenizer class.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Tokenizers;

use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Util;

abstract class Tokenizer
{

    /**
     * The config data for the run.
     *
     * @var \PHP_CodeSniffer\Config
     */
    protected $config = null;

    /**
     * The EOL char used in the content.
     *
     * @var string
     */
    protected $eolChar = [];

    /**
     * A token-based representation of the content.
     *
     * @var array
     */
    protected $tokens = [];

    /**
     * The number of tokens in the tokens array.
     *
     * @var integer
     */
    protected $numTokens = 0;

    /**
     * A list of tokens that are allowed to open a scope.
     *
     * @var array
     */
    public $scopeOpeners = [];

    /**
     * A list of tokens that end the scope.
     *
     * @var array
     */
    public $endScopeTokens = [];

    /**
     * Known lengths of tokens.
     *
     * @var array<int, int>
     */
    public $knownLengths = [];

    /**
     * A list of lines being ignored due to error suppression comments.
     *
     * @var array
     */
    public $ignoredLines = [];


    /**
     * Initialise and run the tokenizer.
     *
     * @param string                         $content The content to tokenize,
     * @param \PHP_CodeSniffer\Config | null $config  The config data for the run.
     * @param string                         $eolChar The EOL char used in the content.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\TokenizerException If the file appears to be minified.
     */
    public function __construct($content, $config, $eolChar='\n')
    {
        $this->eolChar = $eolChar;

        $this->config = $config;
        $this->tokens = $this->tokenize($content);

        if ($config === null) {
            return;
        }

        $this->createPositionMap();
        $this->createTokenMap();
        $this->createParenthesisNestingMap();
        $this->createScopeMap();
        $this->createLevelMap();

        // Allow the tokenizer to do additional processing if required.
        $this->processAdditional();

    }//end __construct()


    /**
     * Checks the content to see if it looks minified.
     *
     * @param string $content The content to tokenize.
     * @param string $eolChar The EOL char used in the content.
     *
     * @return boolean
     */
    protected function isMinifiedContent($content, $eolChar='\n')
    {
        // Minified files often have a very large number of characters per line
        // and cause issues when tokenizing.
        $numChars = strlen($content);
        $numLines = (substr_count($content, $eolChar) + 1);
        $average  = ($numChars / $numLines);
        if ($average > 100) {
            return true;
        }

        return false;

    }//end isMinifiedContent()


    /**
     * Gets the array of tokens.
     *
     * @return array
     */
    public function getTokens()
    {
        return $this->tokens;

    }//end getTokens()


    /**
     * Creates an array of tokens when given some content.
     *
     * @param string $string The string to tokenize.
     *
     * @return array
     */
    abstract protected function tokenize($string);


    /**
     * Performs additional processing after main tokenizing.
     *
     * @return void
     */
    abstract protected function processAdditional();


    /**
     * Sets token position information.
     *
     * Can also convert tabs into spaces. Each tab can represent between
     * 1 and $width spaces, so this cannot be a straight string replace.
     *
     * @return void
     */
    //end createPositionMap()


    /**
     * Replaces tabs in original token content with spaces.
     *
     * Each tab can represent between 1 and $config->tabWidth spaces,
     * so this cannot be a straight string replace. The original content
     * is placed into an orig_content index and the new token length is also
     * set in the length index.
     *
     * @param array  $token    The token to replace tabs inside.
     * @param string $prefix   The character to use to represent the start of a tab.
     * @param string $padding  The character to use to represent the end of a tab.
     * @param int    $tabWidth The number of spaces each tab represents.
     *
     * @return void
     */
    public function replaceTabsInToken(&$token, $prefix=' ', $padding=' ', $tabWidth=null)
    {
        $checkEncoding = false;
        if (function_exists('iconv_strlen') === true) {
            $checkEncoding = true;
        }

        $currColumn = $token['column'];
        if ($tabWidth === null) {
            $tabWidth = $this->config->tabWidth;
            if ($tabWidth === 0) {
                $tabWidth = 1;
            }
        }

        if (rtrim($token['content'], "\t") === '') {
            // String only contains tabs, so we can shortcut the process.
            $numTabs = strlen($token['content']);

            $firstTabSize = ($tabWidth - (($currColumn - 1) % $tabWidth));
            $length       = ($firstTabSize + ($tabWidth * ($numTabs - 1)));
            $newContent   = $prefix.str_repeat($padding, ($length - 1));
        } else {
            // We need to determine the length of each tab.
            $tabs = explode("\t", $token['content']);

            $numTabs    = (count($tabs) - 1);
            $tabNum     = 0;
            $newContent = '';
            $length     = 0;

            foreach ($tabs as $content) {
                if ($content !== '') {
                    $newContent .= $content;
                    if ($checkEncoding === true) {
                        // Not using the default encoding, so take a bit more care.
                        $oldLevel = error_reporting();
                        error_reporting(0);
                        $contentLength = iconv_strlen($content, $this->config->encoding);
                        error_reporting($oldLevel);
                        if ($contentLength === false) {
                            // String contained invalid characters, so revert to default.
                            $contentLength = strlen($content);
                        }
                    } else {
                        $contentLength = strlen($content);
                    }

                    $currColumn += $contentLength;
                    $length     += $contentLength;
                }

                // The last piece of content does not have a tab after it.
                if ($tabNum === $numTabs) {
                    break;
                }

                // Process the tab that comes after the content.
                $lastCurrColumn = $currColumn;
                $tabNum++;

                // Move the pointer to the next tab stop.
                if (($currColumn % $tabWidth) === 0) {
                    // This is the first tab, and we are already at a
                    // tab stop, so this tab counts as a single space.
                    $currColumn++;
                } else {
                    $currColumn++;
                    while (($currColumn % $tabWidth) !== 0) {
                        $currColumn++;
                    }

                    $currColumn++;
                }

                $length     += ($currColumn - $lastCurrColumn);
                $newContent .= $prefix.str_repeat($padding, ($currColumn - $lastCurrColumn - 1));
            }//end foreach
        }//end if

        $token['orig_content'] = $token['content'];
        $token['content']      = $newContent;
        $token['length']       = $length;

    }//end replaceTabsInToken()


    /**
     * Creates a map of brackets positions.
     *
     * @return void
     */
    //end createTokenMap()


    /**
     * Creates a map for the parenthesis tokens that surround other tokens.
     *
     * @return void
     */
    //end createParenthesisNestingMap()


    /**
     * Creates a scope map of tokens that open scopes.
     *
     * @return void
     * @see    recurseScopeMap()
     */
    //end createScopeMap()


    /**
     * Recurses though the scope openers to build a scope map.
     *
     * @param int $stackPtr The position in the stack of the token that
     *                      opened the scope (eg. an IF token or FOR token).
     * @param int $depth    How many scope levels down we are.
     * @param int $ignore   How many curly braces we are ignoring.
     *
     * @return int The position in the stack that closed the scope.
     */
    //end recurseScopeMap()


    /**
     * Constructs the level map.
     *
     * The level map adds a 'level' index to each token which indicates the
     * depth that a token within a set of scope blocks. It also adds a
     * 'conditions' index which is an array of the scope conditions that opened
     * each of the scopes - position 0 being the first scope opener.
     *
     * @return void
     */
    //end createLevelMap()


}//end class
