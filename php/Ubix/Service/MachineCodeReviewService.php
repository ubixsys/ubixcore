<?php

declare(strict_types=1);

namespace Ubix\Service;

use DateTime;
use Exception;
use FilesystemIterator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface as Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface as ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Symfony\Component\Console\Terminal;
use Ubix\Console\Command\AbstractCommand as Command;
use Ubix\DataTransferObject\MachineCodeReviewFileViolation;
use Ubix\DataTransferObject\MachineCodeReviewTestSuite;
use Ubix\Enum\Exception\ExceptionCode;
use Ubix\Enum\MachineCodeReview\MachineCodeReviewTool;
use Ubix\Model\MachineCodeReview;
use Ubix\Service\ProcessServiceInterface as ProcessService;

/**
 * Service to access machine code review
 *
 * @see \Ubix\Tests\Service\MachineCodeReviewServiceTest PHPUnit test case
 */
final class MachineCodeReviewService
{
    public const NO_LINE_NUMBER_TEXT = 'Violation';

    private const LINE_NUMBER_SPACING_CHARACTER = '•';

    private const MAXIMUM_AUTOFIX_RUNS = 3;

    private const OVERFLOWED_LINE_SPACING = ' ';

    private const PHPCS_COMMAND_ARGUMENTS = [ // TEMPORARY: investigate using junit XML output as an alternative
        '--report=json',
        '-s',
        '-v',
    ];

    private const PHPSTAN_COMMAND_ARGUMENTS = [ // TEMPORARY: investigate using junit XML output as an alternative
        '--no-progress',
        '--error-format=json',
        '--memory-limit 1024M',
    ];

    private const PHPSTAN_DEFAULT_FILES = [
        'app',
        'bin',
        'php',
        'public',
        'tests',
    ];

    private const PHPUNIT_COMMAND_ARGUMENTS = [
        '--no-output',
        '--log-junit {$JUNIT_OUTPUT_PATH}',
    ];

    /**
     * Constructor
     *
     * @param Logger             $logger         Logger
     * @param JsonService        $jsonService    JSON service
     * @param ProcessService     $processService Process service
     * @param ProjectRootService $projectRoot    Resolves `vendor/bin/*` and cache paths under the host project root
     */
    public function __construct(
        private Logger $logger, // @phpstan-ignore property.onlyWritten (Logger is a required dependency of most uBixCore classes but has not been implemented in this class yet)
        private JsonService $jsonService,
        private ProcessService $processService,
        private ProjectRootService $projectRoot,
    ) {
    }

    /**
     * Take a machine code review object and display it as output for a command line interface
     *
     * @param MachineCodeReview $review The machine code review
     * @param Output            $output Symfony output
     * @param string[]          $files  The files to perform any follow-up machine code reviews on (optional) (default: [])
     *
     * @return void
     */
    public function displayReview(
        MachineCodeReview $review,
        Output $output,
        array $files = [],
    ): void { // TEMPORARY: does this method make more sense in the MachineCodeReview model?
        //
        //  Show the violations line-by-line
        //
        $this->displayLineByLine($review, $output);

        //
        //  Show a summary of any flagged violations
        //
        $violations            = $review->getViolationsCount();
        $autofixableViolations = $review->getViolationsCount(onlyAutofixable: true);

        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $output->writeln('<fg=cyan>—</>');
        $output->write(PHP_EOL);

        if (count($review->getTools()) === 0) {
            $output->writeln('<fg=red;options=bold>X </> No tools were enabled for this machine code review');
        } else {
            $maximumToolNameLength = max(
                array_map(function ($tool): int {
                    return strlen($tool->name);
                }, $review->getTools()),
            );

            foreach ($review->getTools() as $tool) {
                $toolTests           = $review->getTestsCount(tool: $tool);
                $toolAssertions      = $review->getAssertionsCount(tool: $tool);
                $toolErrors          = $review->getErrorsCount(tool: $tool);
                $toolFailures        = $review->getFailuresCount(tool: $tool);
                $toolViolations      = $review->getViolationsCount(tool: $tool);
                $toolNameWithSpacing = strtolower($tool->name) . str_repeat(' ', $maximumToolNameLength - strlen($tool->name));

                if ($toolViolations > 0) {
                    $output->write('<fg=red;options=bold>X </>' . $toolNameWithSpacing . ' <fg=bright-red>');
                    if ($toolTests > 0) {
                        if ($toolErrors > 0) {
                            $output->write($toolErrors . ' ' . ($toolErrors === 1 ? 'error' : 'errors'));
                        }
                        if ($toolFailures > 0) {
                            if ($toolErrors > 0) {
                                $output->write(', ');
                            }
                            $output->write($toolFailures . ' ' . ($toolFailures === 1 ? 'failure' : 'failures'));
                        }
                    } else {
                        $output->write($toolViolations . ' ' . ($toolViolations === 1 ? 'violation' : 'violations'));
                    }
                    $output->write('</>');
                } else {
                    $output->write('<fg=green;options=bold>✔ </>' . $toolNameWithSpacing);
                    if ($toolTests > 0) {
                        $output->write(' <fg=bright-green>' . $toolTests . ' ' . ($toolTests === 1 ? 'test' : 'tests') . ', ' . $toolAssertions . ' ' . ($toolAssertions === 1 ? 'assertion' : 'assertions') . '</>');
                    }
                }
                $output->write(PHP_EOL);
            }
        }

        $output->write(PHP_EOL);
        $output->write('<options=bold>' . $violations . '</> ' . ($violations === 1 ? 'violation' : 'violations') . ' found');
        if ($autofixableViolations > 0) {
            $output->write(' <fg=magenta;options=bold>' . $autofixableViolations . ' ' . ($autofixableViolations === 1 ? 'is' : 'are') . ' AUTO-FIXABLE</>');
        }

        //
        //  If there are auto-fixable violations present then ask if they should be auto-fixed
        //
        if ($autofixableViolations > 0) {
            $output->write(PHP_EOL);
            $output->write(PHP_EOL);
            $output->write('Auto-fix these violations? <fg=cyan;options=bold>(y/n)</> ');

            $input = fgets(STDIN) ?: '';
            if (strtoupper(trim($input)) === 'Y') {
                //
                //  Perform auto-fixing (we allow autofix to run multiple times to ensure nothing auto-fixable lingers since we autofix one file at a time one rule at time but we do cap that to avoid an infinite loop)
                //
                $autofixRuns = 0;
                do {
                    //
                    //  Auto-fix the files and increment the runs count
                    //
                    $this->autofix($review);
                    $autofixRuns++;

                    //
                    //  Get a fresh machine code review on the auto-fixed files
                    //
                    $review = $this->getReview($files);
                } while ($autofixRuns <= self::MAXIMUM_AUTOFIX_RUNS && $review->getViolationsCount(true) > 0); // As long as we are under the maximum runs and still have auto-fixable errors keep repeating the process

                //
                //  We have now successfully auto-fixed the code so let's find any remaining violations (those that couldn't be auto-fixed) and prompt the developer to report them line-by-line
                //
                $output->write(PHP_EOL);
                $output->write('All auto-fixes have been applied to your code. Checking for remaining violations...');

                if ($review->getViolationsCount() === 0) {
                    $output->write(PHP_EOL);
                    $output->write(PHP_EOL);
                    $output->write('No further violations detected. Please retest your auto-fixed code before committing or merging it.');
                } else {
                    $output->write(PHP_EOL);
                    $output->write(PHP_EOL);
                    $output->write('Do you want to see the line-by-line details of the remaining <fg=red;options=bold>' . $review->getViolationsCount() . ' ' . ($review->getViolationsCount() === 1 ? 'violation' : 'violations') . '</>? <fg=cyan;options=bold>(y/n)</> ');

                    $input = fgets(STDIN) ?: '';
                    if (strtoupper(trim($input)) === 'Y') {
                        $this->displayLineByLine($review, $output);

                        $output->write(PHP_EOL);
                        $output->write(PHP_EOL);
                    }

                    $output->write(PHP_EOL);
                    $output->write('Please fix the remaining violations before committing or merging.');
                }
            }
        }

        //
        //  Extra line for spacing
        //
        $output->write(PHP_EOL);
    }

    /**
     * Get a machine code review
     *
     * @param string[] $files         The files to review (optional) (default: [])
     * @param bool     $enablePhpcs   Whether or not to enable PHP Code Sniffer for the machine code review (optional) (default: true)
     * @param bool     $enablePhpstan Whether or not to enable PHPStan for the machine code review (optional) (default: true)
     * @param bool     $enablePhpunit Whether or not to enable PHPUnit for the machine code review (optional) (default: true)
     * @param ?Output  $output        Symfony output to show progress (optional) (default:null)
     *
     * @throws InvalidArgumentException If all tool enabling flags are set to false
     *
     * @return MachineCodeReview The machine code review
     */
    public function getReview(
        array $files = [],
        bool $enablePhpcs = true,
        bool $enablePhpstan = true,
        bool $enablePhpunit = true,
        ?Output $output = null,
    ): MachineCodeReview {
        //
        //  Validate parameters
        //
        if (!$enablePhpcs && !$enablePhpstan && !$enablePhpunit) {
            throw new InvalidArgumentException(
                'You must enable at least one tool in order to execute a machine code review',
                ExceptionCode::NO_TOOLS_ENABLED_FOR_MACHINE_CODE_REVIEW->value,
            );
        }

        //
        //  If an output parameter was passed then write what is happening and initialize a progress bar
        //
        if ($output !== null) {
            $output->write(PHP_EOL);
            $output->writeln('Performing <fg=cyan;options=bold>machine code review</>, this may take a few minutes...');
            $output->write(PHP_EOL);

            if ($output instanceof ConsoleOutput) {
                $progressBarSection = $output->section();
                $metadataSection    = $output->section();
            } else { // Fallback to the same output for both if sections aren't supported
                $progressBarSection = $output;
                $metadataSection    = $output;
            }

            $progressBar = new ProgressBar(
                $progressBarSection,
                ($enablePhpcs ? 1 : 0) + ($enablePhpstan ? 1 : 0) + ($enablePhpunit ? 1 : 0), // Count the number of tools enabled
            );
            $progressBar->setOverwrite(true);
            $progressBar->start();
        }

        //
        //  Execute the machine code review
        //
        $machineCodeReview = new MachineCodeReview();

        $tools = [];

        if ($enablePhpcs) {
            $tools[] = MachineCodeReviewTool::PHPCS;
        }

        if ($enablePhpstan) {
            $tools[] = MachineCodeReviewTool::PHPSTAN;
        }

        if ($enablePhpunit) {
            $tools[] = MachineCodeReviewTool::PHPUNIT;
        }

        foreach ($tools as $tool) {
            $this->addToolToReview(
                machineCodeReview: $machineCodeReview,
                tool:              $tool,
                files:             $files,
                output:            $metadataSection ?? null,
                progressBar:       $progressBar ?? null,
            );
        }

        //
        //  If an output parameter was passed finish the progress bar we initialized earlier
        //
        if ($output !== null) {
            $progressBar->finish();
        }

        //
        //  Return the machine code review
        //
        return $machineCodeReview;
    }

    /**
     * Display the results of a machine code review line-by-line
     *
     * @param MachineCodeReview $review The machine code review
     * @param Output            $output Symfony output
     *
     * @return void
     */
    public function displayLineByLine(
        MachineCodeReview $review,
        Output $output,
    ): void {
        if ($review->getFiles() !== null) {
            $maximumLineNumberLength = count($review->getFiles()) === 0 ? 0 : max(
                array_map(function ($file): int {
                    return !is_array($file->getViolations()) || count($file->getViolations()) === 0 ? 0 : max(
                        array_map(function ($violation): int {
                            return strlen($violation->lineNumber !== null ? (string)$violation->lineNumber : self::NO_LINE_NUMBER_TEXT);
                        }, $file->getViolations()),
                    );
                }, $review->getFiles()),
            );

            foreach ($review->getFiles() as $file) {
                $violations = $file->getViolations();
                if ($violations !== null && count($violations) > 0) {
                    //
                    //  Output the file details
                    //
                    $slashPosition = strrpos($file->getPath() ?? '', '/');

                    $output->write(PHP_EOL);
                    $output->write(PHP_EOL);
                    $output->write('<fg=red;options=bold>' . count($violations) . ' ' . (count($violations) === 1 ? 'violation' : 'violations') . '</> ');
                    if ($slashPosition !== false) {
                        $output->write('<options=bold>' . substr($file->getPath() ?? '', 0, $slashPosition + 1) . '</>');
                        $output->write('<fg=cyan;options=bold>' . substr($file->getPath() ?? '', $slashPosition + 1) . '</>');
                    } else {
                        $output->write('<fg=cyan;options=bold>' . $file->getPath() . '</>');
                    }

                    //
                    //  Go line-by-line through the file displaying each violation
                    //
                    foreach ($file->getViolations() ?? [] as $violation) {
                        $output->write(PHP_EOL);
                        if ($violation->lineNumber !== null) {
                            $output->write(str_repeat('<fg=#666600>' . self::LINE_NUMBER_SPACING_CHARACTER . '</>', $maximumLineNumberLength - strlen((string)$violation->lineNumber)) . '<fg=bright-yellow>' . $violation->lineNumber . '</> ');
                        } else {
                            $output->write('<fg=bright-yellow>' . self::NO_LINE_NUMBER_TEXT . '</> ');
                        }

                        $violationText = '';
                        if ($violation->autofixable) {
                            $violationText .= '<fg=magenta;options=bold>AUTO-FIXABLE</> ';
                        }
                        if ($violation->testFailure) {
                            $violationText .= '<fg=red>TEST FAILED</> ';
                        }
                        $violationText .= $violation->text;

                        // phpcs:disable

                        /*
                            if (mt_rand(1, 4) === 4) {
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                                $violationText .= (mt_rand(1, 4) === 4 ? "\n" : ' ') . $violation->text;
                            }
                        */

                        // phpcs:enable

                        $violationText .= ' <fg=#666666>—</> <fg=' . Command::COLOR_GRAY . '>' . strtolower($violation->tool->name) . '</><fg=#666666>:</> <fg=' . Command::COLOR_GRAY . '>' . $violation->rule . '</>';

                        $position = 0;
                        while ($position < strlen($violationText)) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
                            $overflowedLineSpacing = $position > 0 ? self::OVERFLOWED_LINE_SPACING : '';
                            $lineNumberOffset      = $maximumLineNumberLength + 1 + strlen($overflowedLineSpacing);

                            //
                            //  Determine the line text
                            //
                            $lineText = substr($violationText, $position, (new Terminal())->getWidth() - $lineNumberOffset); // TEMPORARY: handle counting characters better - right now we count tags even though they won't be rendered on screen so some line breaks will be inserted in the output prematurely (the answer will probably include the $spacePosition code currently appearing later on in this method)

                            // phpcs:disable

                            if (false) { // @phpstan-ignore if.alwaysFalse (Disabled pending the line-wrapping rework below)
                                $remainderText     = substr($violationText, $position);
                                $remainderPosition = 0;

                                $lineText = '';
                                do {
                                    $segmentEndPosition = PHP_INT_MAX;
                                    $segmentEndStrings  = [' ', '<', "\n"];
                                    foreach ($segmentEndStrings as $segmentEndString) {
                                        $stringPosition = strpos($remainderText, $segmentEndString, $remainderPosition);
                                        if ($stringPosition !== false && $stringPosition > 0 && $stringPosition < $segmentEndPosition) {
                                            $segmentEndPosition = $stringPosition;
                                            if ($segmentEndString !== '<') { // We don't want to move past the opening tag but we do want to move past everything else
                                                $segmentEndPosition += strlen($segmentEndString);
                                            }
                                        }
                                    }

                                    $newLineText = substr($remainderText, $remainderPosition, $segmentEndPosition);
                                    if (strlen($lineText) === 0 || strlen(strip_tags($lineText . $newLineText)) < (new Terminal())->getWidth() - $lineNumberOffset) {
                                        $lineText .= $newLineText;

                                        $remainderPosition += strlen($newLineText);
                                    } else {
                                        break;
                                    }
                                } while ($remainderPosition < strlen($remainderText));
                            } else {
                                //
                                //  If there is a mid-line line break then end the current line right after it appears
                                //
                                $lineBreakPosition = strpos($lineText, "\n");
                                if ($lineBreakPosition !== false) {
                                    $lineText = substr($lineText, 0, $lineBreakPosition + strlen("\n"));
                                }

                                //
                                //  Don't break a line in the middle of a word if it can be avoided
                                //
                                $spacePosition = strrpos($lineText, ' ');
                                if ($spacePosition !== false && $spacePosition > 0 && !str_ends_with($violationText, $lineText)) { // We don't want to cut of a line in the middle of a tag so if that scenario is detected change the line to stop immediately before the tag opens
                                    $lineText = substr($lineText, 0, $spacePosition + 1);
                                }

                                //
                                //  Don't break a line in the middle of an open or close tag // TEMPORARY: do open and closing tags need to be on the same line?
                                //
                                $openTagPosition = strrpos($lineText, '<');
                                if ($openTagPosition !== false && strpos($lineText, '>', $openTagPosition) !== false) { // We don't want to cut of a line in the middle of a tag so if that scenario is detected change the line to stop immediately before the tag opens
                                    $lineText = substr($lineText, 0, $openTagPosition);
                                    if ($lineText === '') { // If stopping the line right before the tag opens means the line is now empty then dump the rest of the output and break out of the while loop so we're not stuck in an infinite loop
                                        $output->write(substr($violationText, $position));
                                        break;
                                    }
                                }
                            }

                            // phpcs:enable

                            //
                            //  If this isn't the first line then add a line break and appropriate spacing
                            //
                            if ($position > 0) {
                                $output->write(PHP_EOL);
                                $output->write(str_repeat('<fg=#666600>' . self::LINE_NUMBER_SPACING_CHARACTER . '</>', $maximumLineNumberLength));
                                $output->write(' ');
                                $output->write($overflowedLineSpacing);
                            }

                            //
                            //  Render the line text in the output
                            //
                            $output->write(trim($lineText));

                            //
                            //  Update $position accordingly
                            //
                            $position += strlen($lineText);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param MachineCodeReview     $machineCodeReview The machine code review
     * @param MachineCodeReviewTool $tool              The machine code review tool to add
     * @param string[]              $files             The files to review
     * @param ?Output               $output            Symfony output
     * @param ?ProgressBar          $progressBar       Symfony progress bar
     *
     * @return void
     */
    private function addToolToReview(MachineCodeReview $machineCodeReview, MachineCodeReviewTool $tool, array $files, ?Output $output, ?ProgressBar $progressBar): void
    {
        $start = new DateTime();

        if ($output !== null) {
            $output->write(PHP_EOL);
            $output->write('Running <options=bold>' . strtolower($tool->name) . '</>...');
        }

        switch ($tool) {
            case MachineCodeReviewTool::PHPCS:
                $machineCodeReview->merge($this->getPhpcsReview($files));
                break;

            case MachineCodeReviewTool::PHPSTAN:
                $machineCodeReview->merge($this->getPhpstanReview($files));
                break;

            case MachineCodeReviewTool::PHPUNIT:
                $machineCodeReview->merge($this->getPhpunitReview());
                break;
        }

        if ($output !== null) {
            $elapsed = (new DateTime())->getTimestamp() - $start->getTimestamp();
            $output->write(' <fg=' . Command::COLOR_GRAY . '>' . $elapsed . ' ' . ($elapsed === 1 ? 'second' : 'seconds') . '</> <fg=green>✔ </>');

            $progressBar?->advance();
        }
    }

    /**
     * Auto-fix violations from a machine code review
     *
     * @param MachineCodeReview $review The machine code review
     *
     * @return void
     */
    private function autofix(MachineCodeReview $review): void
    {
        foreach ($review->getFiles() ?? [] as $file) {
            // NOT_IMPLEMENTED: rather than do one file at at a time, one rule at a time it would probably be better to try to do one file at a time, all rules as a first attempt and only fallback to the "one rule at a time" tactic if the mass fixing didn't work
            $tools = [];

            foreach ($file->getViolations() ?? [] as $violation) {
                if ($violation->autofixable) {
                    //
                    //  Ensure the tool is in our $tools array
                    //
                    if (!array_key_exists($violation->tool->name, $tools)) {
                        $tools[$violation->tool->name] = [];
                    }

                    //
                    //  Ensure the rule is in the $tools array entry
                    //
                    $rule      = $violation->rule;
                    $ruleParts = explode('.', $rule);
                    if ($violation->tool === MachineCodeReviewTool::PHPCS && count($ruleParts) > 3) { // The phpcs rules include a "sub-rule" that is not fixable, e.g. the Squiz.WhiteSpace.SuperfluousWhitespace.EndLine sub-rule is not auto-fixable but the broader Squiz.WhiteSpace.SuperfluousWhitespace rule is
                        $rule = $ruleParts[0] . '.' . $ruleParts[1] . '.' . $ruleParts[2];
                    }

                    if (!in_array($rule, $tools[$violation->tool->name], true)) {
                        $tools[$violation->tool->name][] = $rule;
                    }
                }
            }

            $toolEnumReflection = new ReflectionClass(MachineCodeReviewTool::class);
            foreach ($toolEnumReflection->getConstants() as $tool) {
                assert($tool instanceof MachineCodeReviewTool);

                if (array_key_exists($tool->name, $tools)) {
                    switch ($tool) {
                        case MachineCodeReviewTool::PHPCS:
                            $this->phpcsAutofix(
                                files: [$file->getPath() ?? ''],
                                rules: $tools[$tool->name],
                            );
                            break;
                    }
                }
            }
        }
    }

    /**
     * Get a machine code review with PHP Code Sniffer
     *
     * @param string[] $files The files to review (optional) (default: [])
     *
     * @return MachineCodeReview The machine code review
     */
    private function getPhpcsReview(array $files = []): MachineCodeReview
    {
        //
        //  Initialize a machine code review
        //
        $machineCodeReview = new MachineCodeReview();

        //
        //  Run the command and process the output
        //
        $command = '"' . $this->projectRoot->getVendorBinPath('phpcs') . '" ' . implode(' ', self::PHPCS_COMMAND_ARGUMENTS);
        if (count($files) > 0) {
            $command .= ' ' . implode(' ', $files);
        }
        if ($this->phpIsOnWindows()) {
            $command = str_replace('/', '\\', $command);
        }

        $output = null;
        exec($command, $output);

        //
        //  Process the results
        //
        $output = implode(PHP_EOL, $output);

        $startOfJson = strpos($output, '{');
        if ($startOfJson === false || strpos($output, '}', $startOfJson) === false) {
            //
            //  The command failed to return JSON meaning no results are known
            //
            $machineCodeReview->addViolation(
                path:      $this->projectRoot->getRoot(),
                violation: new MachineCodeReviewFileViolation(
                    text: 'JSON was not found in the output (no data returned)',
                    tool: MachineCodeReviewTool::PHPCS,
                    rule: 'error',
                ),
            );
            return $machineCodeReview;
        }

        foreach ($this->jsonService->decode(substr($output, $startOfJson)) as $key => $value) {
            if ($key === 'files' && is_array($value)) {
                /**
                 * @var array{
                 *     messages?: array<mixed>
                 * } $file
                 */
                foreach ($value as $path => $file) {
                    $machineCodeReview->addTool(
                        path: $path,
                        tool: MachineCodeReviewTool::PHPCS,
                    );

                    if (isset($file['messages']) && count($file['messages']) > 0) {
                        /**
                         * @var array{
                         *     message?: string,
                         *     source?: string,
                         *     line?: int,
                         *     fixable?: bool,
                         * } $violation
                         */
                        foreach ($file['messages'] as $violation) {
                            $machineCodeReview->addViolation(
                                path:      $path,
                                violation: new MachineCodeReviewFileViolation(
                                    text:           $violation['message'] ?? '',
                                    tool:           MachineCodeReviewTool::PHPCS,
                                    rule:           $violation['source'] ?? '',
                                    lineNumber:     $violation['line'] ?? null,
                                    columnPosition: $violation['column'] ?? null,
                                    autofixable:    $violation['fixable'] ?? false,
                                ),
                            );
                        }
                    }
                }
            }
        }

        //
        //  Return the machine code review
        //
        return $machineCodeReview;
    }

    /**
     * Auto-fix with PHP Code Beautifier/Fixer (bundled with PHP Code Sniffer)
     *
     * @param string[] $files The files to auto-fix (optional) (default: [])
     * @param string[] $rules The rules to auto-fix (optional) (default: [])
     *
     * @return void
     */
    private function phpcsAutofix(array $files = [], array $rules = []): void
    {
        //
        //  If the arrays passed in are empty change them to an array with an empty string to ensure everything runs at least once
        //
        if (count($files) === 0) {
            $files = [''];
        }

        if (count($rules) === 0) {
            $rules = [''];
        }

        //
        //  Run the phpcbf command(s) to perform the auto-fixing
        //
        foreach ($files as $file) {
            foreach ($rules as $rule) {
                $command = $this->projectRoot->getVendorBinPath('phpcbf');
                if ($file !== '') {
                    $command .= ' ' . $file;
                }
                if ($rule !== '') {
                    $command .= ' --sniffs=' . $rule;
                }

                if ($this->phpIsOnWindows()) {
                    $command = str_replace('/', '\\', $command);
                }

                exec($command, $output);
            }
        }
    }

    /**
     * Get a machine code review with PHPStan
     *
     * @param string[] $files The files to review (optional) (default: [])
     *
     * @return MachineCodeReview The machine code review
     */
    private function getPhpstanReview(array $files = []): MachineCodeReview
    {
        //
        //  Initialize a machine code review
        //
        $machineCodeReview = new MachineCodeReview();

        //
        //  Run the command
        //
        $command = '"' . $this->projectRoot->getVendorBinPath('phpstan') . '" analyze ' . implode(' ', self::PHPSTAN_COMMAND_ARGUMENTS) . ' ' . implode(' ', count($files) > 0 ? $files : array_filter(self::PHPSTAN_DEFAULT_FILES, function (string $dir): bool {
            return is_dir($this->projectRoot->getPath($dir)); // A framework-only checkout has no app/
        }));
        if ($this->phpIsOnWindows()) {
            $command = str_replace(
                '/',
                '\\',
                $command,
            );
        }

        $commandResults = $this->processService->executeAsSubprocess($command);

        //
        //  Process the results
        //
        $startOfJson = strpos($commandResults->stdoutOutput, '{');
        if ($startOfJson === false || strpos($commandResults->stdoutOutput, '}', $startOfJson) === false) {
            //
            //  The command failed to return JSON meaning no results are known
            //
            $machineCodeReview->addViolation(
                path:      $this->projectRoot->getRoot(),
                violation: new MachineCodeReviewFileViolation(
                    text: 'JSON was not found in the output (no data returned)',
                    tool: MachineCodeReviewTool::PHPSTAN,
                    rule: 'error',
                ),
            );
            return $machineCodeReview;
        }

        foreach ($this->getPhpstanFilesAnalyzed($files) as $path) {
            $machineCodeReview->addTool(
                path: $path,
                tool: MachineCodeReviewTool::PHPSTAN,
            );
        }

        foreach ($this->jsonService->decode(substr($commandResults->stdoutOutput, $startOfJson)) as $key => $value) {
            if ($key === 'files' && is_array($value)) {
                /**
                 * @var array{
                 *     messages?: array<mixed>,
                 * } $file
                 */
                foreach ($value as $path => $file) {
                    if (array_key_exists('messages', $file) && count($file['messages']) > 0) {
                        /**
                         * @var array{
                         *     message?: string,
                         *     identifier?: string,
                         *     line?: int,
                         * } $violation
                         */
                        foreach ($file['messages'] as $violation) {
                            $machineCodeReview->addViolation(
                                path:      $path,
                                violation: new MachineCodeReviewFileViolation(
                                    text:       $violation['message'] ?? '',
                                    tool:       MachineCodeReviewTool::PHPSTAN,
                                    rule:       $violation['identifier'] ?? '',
                                    lineNumber: $violation['line'] ?? null,
                                ),
                            );
                        }
                    }
                }
            }
        }

        //
        //  Return the machine code review
        //
        return $machineCodeReview;
    }

    /**
     * Get the files analyzed by PHPStan
     *
     * A run limited to specific files never writes PHPStan's result cache (any cache on disk is stale from an earlier
     * run), and its format is internal and changes between versions, so the cache is only trusted after a full run and
     * only when it has the expected shape. Otherwise the files PHPStan was asked to analyze are used.
     *
     * @param string[] $files The files PHPStan was asked to analyze (optional) (default: [] meaning the default paths)
     *
     * @return string[] The files analyzed by PHPStan
     */
    private function getPhpstanFilesAnalyzed(array $files = []): array
    {
        if (count($files) > 0) {
            return array_values(array_unique($files));
        }

        $phpstanCachePath = '.phpstan.cache'; // NOT_IMPLEMENTED: read this direct from phpstan.neon - I don't want it hardcoded (DRY violation)

        $cacheFile = $this->projectRoot->getPath($phpstanCachePath, 'resultCache.php');
        $cache     = is_file($cacheFile) ? @include $cacheFile : null; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a truncated or foreign-format cache must degrade, not crash
        if (!is_array($cache)) {
            return $this->getPhpstanDefaultFiles();
        }

        // PHPStan’s cache is an array; one entry carries the dependency tree of project files.
        // Different versions label it slightly differently, so handle the common shapes:
        $keysToTry = ['dependencies', 'files', 'fileDependencies'];

        $all = [];
        foreach ($keysToTry as $k) {
            if (!isset($cache[$k]) || !is_array($cache[$k])) {
                continue;
            }
            // Collect only string keys (absolute file paths).
            foreach ($cache[$k] as $maybePath => $unused) { // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
                if (is_string($maybePath)) {
                    $all[] = $maybePath;
                }
            }
        }

        return count($all) > 0 ? array_values(array_unique($all)) : $this->getPhpstanDefaultFiles();
    }

    /**
     * Get the PHP files under the default paths PHPStan analyzes
     *
     * @return string[] The files
     */
    private function getPhpstanDefaultFiles(): array
    {
        $found = [];
        foreach (self::PHPSTAN_DEFAULT_FILES as $dir) {
            $path = $this->projectRoot->getPath($dir);
            if (!is_dir($path)) {
                continue; // A framework-only checkout has no app/
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    /**
     * Get a machine code review with PHPUnit
     *
     * @param string[] $files The files to review (optional) (default: [])
     *
     * @throws Exception If valid XML is not found in the output
     *
     * @return MachineCodeReview The machine code review
     */
    private function getPhpunitReview(array $files = []): MachineCodeReview
    {
        //
        //  Initialize a machine code review
        //
        $machineCodeReview = new MachineCodeReview();

        //
        //  Run the command and process the output
        //
        $tmpPath = tempnam(sys_get_temp_dir(), 'MachineCodeReview');
        if ($tmpPath === false) {
            throw new Exception(
                'Failed to create a temporary file to store phpunit output',
                ExceptionCode::TEMPORARY_FILE_CREATION_ERROR->value,
            );
        }

        $command = '"' . $this->projectRoot->getVendorBinPath('phpunit') . '" ' . str_replace('{$JUNIT_OUTPUT_PATH}', $tmpPath, implode(' ', self::PHPUNIT_COMMAND_ARGUMENTS));

        if (count($files) > 0) {
            $command .= ' ' . implode(',', $files);
        }
        if ($this->phpIsOnWindows()) {
            $command = str_replace('/', '\\', $command);
        }

        $output = null;
        exec($command, $output);

        //
        //  Process the results
        //
        $xml = simplexml_load_string(file_get_contents($tmpPath) ?: '');
        if ($xml === false) {
            //
            //  The command failed to return valid XML meaning no results are known
            //
            $machineCodeReview->addViolation(
                path:      $this->projectRoot->getRoot(),
                violation: new MachineCodeReviewFileViolation(
                    text: 'Valid XML was not found in the output (no data returned)',
                    tool: MachineCodeReviewTool::PHPUNIT,
                    rule: 'error',
                ),
            );
        } else {
            //
            //  Loop through each test suite that was run
            //
            foreach ($xml->testsuite->testsuite->testsuite as $testSuite) {
                $machineCodeReview->addTestSuite(
                    path:      (string)$testSuite['file'],
                    testSuite: new MachineCodeReviewTestSuite(
                        tool:       MachineCodeReviewTool::PHPUNIT,
                        tests:      (int)$testSuite['tests'],
                        assertions: (int)$testSuite['assertions'],
                        errors:     (int)$testSuite['errors'],
                        failures:   (int)$testSuite['failures'],
                        skipped:    (int)$testSuite['skipped'],
                    ),
                );

                //
                //  Loop through each test case and look for failures
                //
                foreach ($testSuite->testcase as $testCase) {
                    if (isset($testCase->error) || isset($testCase->failure)) {
                        $text = isset($testCase->error) ? (string)$testCase->error : (string)$testCase->failure;

                        $machineCodeReview->addViolation(
                            path:      (string)$testSuite['file'],
                            violation: new MachineCodeReviewFileViolation(
                                text:        $text,
                                tool:        MachineCodeReviewTool::PHPUNIT,
                                rule:        (string)$testSuite['name'] . '::' . (string)$testCase['name'],
                                testFailure: true,
                                lineNumber:  preg_match('/:\s*(?P<lineNumber>\d+)\s*$/', $text, $regex) ? (int)$regex['lineNumber'] : null, // The text should end with a semicolon then a numeric value representing the line number
                            ),
                        );
                    }
                }
            }
        }

        //
        //  Return the machine code review
        //
        return $machineCodeReview;
    }

    /**
     * Determine whether or not the PHP environment is running on Windows
     *
     * @return bool Whether or not the PHP environment is running on Windows
     */
    private function phpIsOnWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}
