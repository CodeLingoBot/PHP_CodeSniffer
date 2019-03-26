<?php
/**
 * Checks the indentation of embedded PHP code segments.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Standards\Squiz\Sniffs\PHP;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

class EmbeddedPhpSniff implements Sniff
{


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return [T_OPEN_TAG];

    }//end register()


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in the
     *                                               stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // If the close php tag is on the same line as the opening
        // then we have an inline embedded PHP block.
        $closeTag = $phpcsFile->findNext(T_CLOSE_TAG, $stackPtr);
        if ($closeTag === false || $tokens[$stackPtr]['line'] !== $tokens[$closeTag]['line']) {
            $this->validateMultilineEmbeddedPhp($phpcsFile, $stackPtr);
        } else {
            $this->validateInlineEmbeddedPhp($phpcsFile, $stackPtr);
        }

    }//end process()


    /**
     * Validates embedded PHP that exists on multiple lines.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in the
     *                                               stack passed in $tokens.
     *
     * @return void
     */
    //end validateMultilineEmbeddedPhp()


    /**
     * Validates embedded PHP that exists on one line.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in the
     *                                               stack passed in $tokens.
     *
     * @return void
     */
    //end validateInlineEmbeddedPhp()


}//end class
