<?php
/**
 * Responsible for running PHPCS and PHPCBF.
 *
 * After creating an object of this class, you probably just want to
 * call runPHPCS() or runPHPCBF().
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer;

use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Util\Cache;
use PHP_CodeSniffer\Util\Common;
use PHP_CodeSniffer\Util\Standards;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Exceptions\DeepExitException;

class Runner
{

    /**
     * The config data for the run.
     *
     * @var \PHP_CodeSniffer\Config
     */
    public $config = null;

    /**
     * The ruleset used for the run.
     *
     * @var \PHP_CodeSniffer\Ruleset
     */
    public $ruleset = null;

    /**
     * The reporter used for generating reports after the run.
     *
     * @var \PHP_CodeSniffer\Reporter
     */
    public $reporter = null;


    /**
     * Run the PHPCS script.
     *
     * @return array
     */
    public function runPHPCS()
    {
        try {
            Util\Timing::startTiming();
            Runner::checkRequirements();

            if (defined('PHP_CODESNIFFER_CBF') === false) {
                define('PHP_CODESNIFFER_CBF', false);
            }

            // Creating the Config object populates it with all required settings
            // based on the CLI arguments provided to the script and any config
            // values the user has set.
            $this->config = new Config();

            // Init the run and load the rulesets to set additional config vars.
            $this->init();

            // Print a list of sniffs in each of the supplied standards.
            // We fudge the config here so that each standard is explained in isolation.
            if ($this->config->explain === true) {
                $standards = $this->config->standards;
                foreach ($standards as $standard) {
                    $this->config->standards = [$standard];
                    $ruleset = new Ruleset($this->config);
                    $ruleset->explain();
                }

                return 0;
            }

            // Generate documentation for each of the supplied standards.
            if ($this->config->generator !== null) {
                $standards = $this->config->standards;
                foreach ($standards as $standard) {
                    $this->config->standards = [$standard];
                    $ruleset   = new Ruleset($this->config);
                    $class     = 'PHP_CodeSniffer\Generators\\'.$this->config->generator;
                    $generator = new $class($ruleset);
                    $generator->generate();
                }

                return 0;
            }

            // Other report formats don't really make sense in interactive mode
            // so we hard-code the full report here and when outputting.
            // We also ensure parallel processing is off because we need to do one file at a time.
            if ($this->config->interactive === true) {
                $this->config->reports      = ['full' => null];
                $this->config->parallel     = 1;
                $this->config->showProgress = false;
            }

            // Disable caching if we are processing STDIN as we can't be 100%
            // sure where the file came from or if it will change in the future.
            if ($this->config->stdin === true) {
                $this->config->cache = false;
            }

            $numErrors = $this->run();

            // Print all the reports for this run.
            $toScreen = $this->reporter->printReports();

            // Only print timer output if no reports were
            // printed to the screen so we don't put additional output
            // in something like an XML report. If we are printing to screen,
            // the report types would have already worked out who should
            // print the timer info.
            if ($this->config->interactive === false
                && ($toScreen === false
                || (($this->reporter->totalErrors + $this->reporter->totalWarnings) === 0 && $this->config->showProgress === true))
            ) {
                Util\Timing::printRunTime();
            }
        } catch (DeepExitException $e) {
            echo $e->getMessage();
            return $e->getCode();
        }//end try

        if ($numErrors === 0) {
            // No errors found.
            return 0;
        } else if ($this->reporter->totalFixable === 0) {
            // Errors found, but none of them can be fixed by PHPCBF.
            return 1;
        } else {
            // Errors found, and some can be fixed by PHPCBF.
            return 2;
        }

    }//end runPHPCS()


    /**
     * Run the PHPCBF script.
     *
     * @return array
     */
    public function runPHPCBF()
    {
        if (defined('PHP_CODESNIFFER_CBF') === false) {
            define('PHP_CODESNIFFER_CBF', true);
        }

        try {
            Util\Timing::startTiming();
            Runner::checkRequirements();

            // Creating the Config object populates it with all required settings
            // based on the CLI arguments provided to the script and any config
            // values the user has set.
            $this->config = new Config();

            // When processing STDIN, we can't output anything to the screen
            // or it will end up mixed in with the file output.
            if ($this->config->stdin === true) {
                $this->config->verbosity = 0;
            }

            // Init the run and load the rulesets to set additional config vars.
            $this->init();

            // When processing STDIN, we only process one file at a time and
            // we don't process all the way through, so we can't use the parallel
            // running system.
            if ($this->config->stdin === true) {
                $this->config->parallel = 1;
            }

            // Override some of the command line settings that might break the fixes.
            $this->config->generator    = null;
            $this->config->explain      = false;
            $this->config->interactive  = false;
            $this->config->cache        = false;
            $this->config->showSources  = false;
            $this->config->recordErrors = false;
            $this->config->reportFile   = null;
            $this->config->reports      = ['cbf' => null];

            // If a standard tries to set command line arguments itself, some
            // may be blocked because PHPCBF is running, so stop the script
            // dying if any are found.
            $this->config->dieOnUnknownArg = false;

            $this->run();
            $this->reporter->printReports();

            echo PHP_EOL;
            Util\Timing::printRunTime();
        } catch (DeepExitException $e) {
            echo $e->getMessage();
            return $e->getCode();
        }//end try

        if ($this->reporter->totalFixed === 0) {
            // Nothing was fixed by PHPCBF.
            if ($this->reporter->totalFixable === 0) {
                // Nothing found that could be fixed.
                return 0;
            } else {
                // Something failed to fix.
                return 2;
            }
        }

        if ($this->reporter->totalFixable === 0) {
            // PHPCBF fixed all fixable errors.
            return 1;
        }

        // PHPCBF fixed some fixable errors, but others failed to fix.
        return 2;

    }//end runPHPCBF()


    /**
     * Exits if the minimum requirements of PHP_CodeSniffer are not met.
     *
     * @return array
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     */
    public function checkRequirements()
    {
        // Check the PHP version.
        if (PHP_VERSION_ID < 50400) {
            $error = 'ERROR: PHP_CodeSniffer requires PHP version 5.4.0 or greater.'.PHP_EOL;
            throw new DeepExitException($error, 3);
        }

        $requiredExtensions = [
            'tokenizer',
            'xmlwriter',
            'SimpleXML',
        ];
        $missingExtensions  = [];

        foreach ($requiredExtensions as $extension) {
            if (extension_loaded($extension) === false) {
                $missingExtensions[] = $extension;
            }
        }

        if (empty($missingExtensions) === false) {
            $last      = array_pop($requiredExtensions);
            $required  = implode(', ', $requiredExtensions);
            $required .= ' and '.$last;

            if (count($missingExtensions) === 1) {
                $missing = $missingExtensions[0];
            } else {
                $last     = array_pop($missingExtensions);
                $missing  = implode(', ', $missingExtensions);
                $missing .= ' and '.$last;
            }

            $error = 'ERROR: PHP_CodeSniffer requires the %s extensions to be enabled. Please enable %s.'.PHP_EOL;
            $error = sprintf($error, $required, $missing);
            throw new DeepExitException($error, 3);
        }

    }//end checkRequirements()


    /**
     * Init the rulesets and other high-level settings.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     */
    public function init()
    {
        if (defined('PHP_CODESNIFFER_CBF') === false) {
            define('PHP_CODESNIFFER_CBF', false);
        }

        // Ensure this option is enabled or else line endings will not always
        // be detected properly for files created on a Mac with the /r line ending.
        ini_set('auto_detect_line_endings', true);

        // Check that the standards are valid.
        foreach ($this->config->standards as $standard) {
            if (Util\Standards::isInstalledStandard($standard) === false) {
                // They didn't select a valid coding standard, so help them
                // out by letting them know which standards are installed.
                $error = 'ERROR: the "'.$standard.'" coding standard is not installed. ';
                ob_start();
                Util\Standards::printInstalledStandards();
                $error .= ob_get_contents();
                ob_end_clean();
                throw new DeepExitException($error, 3);
            }
        }

        // Saves passing the Config object into other objects that only need
        // the verbosity flag for debug output.
        if (defined('PHP_CODESNIFFER_VERBOSITY') === false) {
            define('PHP_CODESNIFFER_VERBOSITY', $this->config->verbosity);
        }

        // Create this class so it is autoloaded and sets up a bunch
        // of PHP_CodeSniffer-specific token type constants.
        $tokens = new Util\Tokens();

        // Allow autoloading of custom files inside installed standards.
        $installedStandards = Standards::getInstalledStandardDetails();
        foreach ($installedStandards as $name => $details) {
            Autoload::addSearchPath($details['path'], $details['namespace']);
        }

        // The ruleset contains all the information about how the files
        // should be checked and/or fixed.
        try {
            $this->ruleset = new Ruleset($this->config);
        } catch (RuntimeException $e) {
            $error  = 'ERROR: '.$e->getMessage().PHP_EOL.PHP_EOL;
            $error .= $this->config->printShortUsage(true);
            throw new DeepExitException($error, 3);
        }

    }//end init()


    /**
     * Performs the run.
     *
     * @return int The number of errors and warnings found.
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     * @throws \PHP_CodeSniffer\Exceptions\RuntimeException
     */
    //end run()


    /**
     * Converts all PHP errors into exceptions.
     *
     * This method forces a sniff to stop processing if it is not
     * able to handle a specific piece of code, instead of continuing
     * and potentially getting into a loop.
     *
     * @param int    $code    The level of error raised.
     * @param string $message The error message.
     * @param string $file    The path of the file that raised the error.
     * @param int    $line    The line number the error was raised at.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\RuntimeException
     */
    public function handleErrors($code, $message, $file, $line)
    {
        if ((error_reporting() & $code) === 0) {
            // This type of error is being muted.
            return true;
        }

        throw new RuntimeException("$message in $file on line $line");

    }//end handleErrors()


    /**
     * Processes a single file, including checking and fixing.
     *
     * @param \PHP_CodeSniffer\Files\File $file The file to be processed.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     */
    public function processFile($file)
    {
        if (PHP_CODESNIFFER_VERBOSITY > 0) {
            $startTime = microtime(true);
            echo 'Processing '.basename($file->path).' ';
            if (PHP_CODESNIFFER_VERBOSITY > 1) {
                echo PHP_EOL;
            }
        }

        try {
            $file->process();

            if (PHP_CODESNIFFER_VERBOSITY > 0) {
                $timeTaken = ((microtime(true) - $startTime) * 1000);
                if ($timeTaken < 1000) {
                    $timeTaken = round($timeTaken);
                    echo "DONE in {$timeTaken}ms";
                } else {
                    $timeTaken = round(($timeTaken / 1000), 2);
                    echo "DONE in $timeTaken secs";
                }

                if (PHP_CODESNIFFER_CBF === true) {
                    $errors = $file->getFixableCount();
                    echo " ($errors fixable violations)".PHP_EOL;
                } else {
                    $errors   = $file->getErrorCount();
                    $warnings = $file->getWarningCount();
                    echo " ($errors errors, $warnings warnings)".PHP_EOL;
                }
            }
        } catch (\Exception $e) {
            $error = 'An error occurred during processing; checking has been aborted. The error message was: '.$e->getMessage();
            $file->addErrorOnLine($error, 1, 'Internal.Exception');
        }//end try

        $this->reporter->cacheFileReport($file, $this->config);

        if ($this->config->interactive === true) {
            /*
                Running interactively.
                Print the error report for the current file and then wait for user input.
            */

            // Get current violations and then clear the list to make sure
            // we only print violations for a single file each time.
            $numErrors = null;
            while ($numErrors !== 0) {
                $numErrors = ($file->getErrorCount() + $file->getWarningCount());
                if ($numErrors === 0) {
                    continue;
                }

                $this->reporter->printReport('full');

                echo '<ENTER> to recheck, [s] to skip or [q] to quit : ';
                $input = fgets(STDIN);
                $input = trim($input);

                switch ($input) {
                case 's':
                    break(2);
                case 'q':
                    throw new DeepExitException('', 0);
                default:
                    // Repopulate the sniffs because some of them save their state
                    // and only clear it when the file changes, but we are rechecking
                    // the same file.
                    $file->ruleset->populateTokenListeners();
                    $file->reloadContent();
                    $file->process();
                    $this->reporter->cacheFileReport($file, $this->config);
                    break;
                }
            }//end while
        }//end if

        // Clean up the file to save (a lot of) memory.
        $file->cleanUp();

    }//end processFile()


    /**
     * Waits for child processes to complete and cleans up after them.
     *
     * The reporting information returned by each child process is merged
     * into the main reporter class.
     *
     * @param array $childProcs An array of child processes to wait for.
     *
     * @return void
     */
    //end processChildProcs()


    /**
     * Print progress information for a single processed file.
     *
     * @param \PHP_CodeSniffer\Files\File $file         The file that was processed.
     * @param int                         $numFiles     The total number of files to process.
     * @param int                         $numProcessed The number of files that have been processed,
     *                                                  including this one.
     *
     * @return void
     */
    public function printProgress(File $file, $numFiles, $numProcessed)
    {
        if (PHP_CODESNIFFER_VERBOSITY > 0
            || $this->config->showProgress === false
        ) {
            return;
        }

        // Show progress information.
        if ($file->ignored === true) {
            echo 'S';
        } else {
            $errors   = $file->getErrorCount();
            $warnings = $file->getWarningCount();
            $fixable  = $file->getFixableCount();
            $fixed    = $file->getFixedCount();

            if (PHP_CODESNIFFER_CBF === true) {
                // Files with fixed errors or warnings are F (green).
                // Files with unfixable errors or warnings are E (red).
                // Files with no errors or warnings are . (black).
                if ($fixable > 0) {
                    if ($this->config->colors === true) {
                        echo "\033[31m";
                    }

                    echo 'E';

                    if ($this->config->colors === true) {
                        echo "\033[0m";
                    }
                } else if ($fixed > 0) {
                    if ($this->config->colors === true) {
                        echo "\033[32m";
                    }

                    echo 'F';

                    if ($this->config->colors === true) {
                        echo "\033[0m";
                    }
                } else {
                    echo '.';
                }//end if
            } else {
                // Files with errors are E (red).
                // Files with fixable errors are E (green).
                // Files with warnings are W (yellow).
                // Files with fixable warnings are W (green).
                // Files with no errors or warnings are . (black).
                if ($errors > 0) {
                    if ($this->config->colors === true) {
                        if ($fixable > 0) {
                            echo "\033[32m";
                        } else {
                            echo "\033[31m";
                        }
                    }

                    echo 'E';

                    if ($this->config->colors === true) {
                        echo "\033[0m";
                    }
                } else if ($warnings > 0) {
                    if ($this->config->colors === true) {
                        if ($fixable > 0) {
                            echo "\033[32m";
                        } else {
                            echo "\033[33m";
                        }
                    }

                    echo 'W';

                    if ($this->config->colors === true) {
                        echo "\033[0m";
                    }
                } else {
                    echo '.';
                }//end if
            }//end if
        }//end if

        $numPerLine = 60;
        if ($numProcessed !== $numFiles && ($numProcessed % $numPerLine) !== 0) {
            return;
        }

        $percent = round(($numProcessed / $numFiles) * 100);
        $padding = (strlen($numFiles) - strlen($numProcessed));
        if ($numProcessed === $numFiles && $numFiles > $numPerLine) {
            $padding += ($numPerLine - ($numFiles - (floor($numFiles / $numPerLine) * $numPerLine)));
        }

        echo str_repeat(' ', $padding)." $numProcessed / $numFiles ($percent%)".PHP_EOL;

    }//end printProgress()


}//end class
