<?php
/**
 * Ensures a file declares new symbols and causes no other side effects, or executes logic with side effects, but not both.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Standards\PSR1\Sniffs\Files;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

class SideEffectsSniff implements Sniff
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
     * Processes this sniff, when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in
     *                                               the token stack.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        $result = $this->searchForConflict($phpcsFile, 0, ($phpcsFile->numTokens - 1), $tokens);

        if ($result['symbol'] !== null && $result['effect'] !== null) {
            $error = 'A file should declare new symbols (classes, functions, constants, etc.) and cause no other side effects, or it should execute logic with side effects, but should not do both. The first symbol is defined on line %s and the first side effect is on line %s.';
            $data  = [
                $tokens[$result['symbol']]['line'],
                $tokens[$result['effect']]['line'],
            ];
            $phpcsFile->addWarning($error, 0, 'FoundWithSymbols', $data);
            $phpcsFile->recordMetric($stackPtr, 'Declarations and side effects mixed', 'yes');
        } else {
            $phpcsFile->recordMetric($stackPtr, 'Declarations and side effects mixed', 'no');
        }

        // Ignore the rest of the file.
        return ($phpcsFile->numTokens + 1);

    }//end process()


    /**
     * Searches for symbol declarations and side effects.
     *
     * Returns the positions of both the first symbol declared and the first
     * side effect in the file. A NULL value for either indicates nothing was
     * found.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $start     The token to start searching from.
     * @param int                         $end       The token to search to.
     * @param array                       $tokens    The stack of tokens that make up
     *                                               the file.
     *
     * @return array
     */
    //end searchForConflict()


}//end class
