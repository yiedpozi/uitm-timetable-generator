<?php

namespace App\Telegram\Commands;

use App\Services\IcressService;
use App\Telegram\Commands\Traits\CommandTrait;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\ChatAction;
use PhpTelegramBot\Laravel\Telegram\Conversation\LeadsConversation;

class GenerateCommand extends UserCommand
{
    use LeadsConversation, CommandTrait;

    protected $name = 'generate';
    protected $description = 'Generate UiTM timetable.';
    protected $usage = '/generate';
    protected $version = '1.0.0';

    private $icressService;

    /**
     * Constructor
     *
     * @param \Longman\TelegramBot\Telegram  $telegram
     * @param \Longman\TelegramBot\Entities\Update|null  $update
     */
    public function __construct(Telegram $telegram, ?Update $update = null)
    {
        parent::__construct($telegram, $update);

        $this->icressService = new IcressService();
    }

    /**
     * Execute command.
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage() ?: $this->getEditedMessage();
        $chat    = $message->getChat();
        $chatId  = $chat->getId();
        $text    = trim($message->getText(true));

        if (!$message || !$chatId) {
            return Request::emptyResponse();
        }

        $conversation = $this->conversation();
        $conversationStep = $conversation->get('step', 0);

        switch ($conversationStep) {
            case 0:
                // If the user is just starting the conversation, reply with a list of campuses to be selected
                if ($text === '') {
                    $conversation->persist(['step' => 0]);

                    return $this->sendCampuses();
                }

                $campusId = $this->icressService->getCampusIdByName($text);

                if (!$campusId) {
                    return $this->replyToChat(__('uitmtimetable.telegram_bot.invalid_campus'));
                }

                // Save the selected campus in the conversation context
                $conversation->persist(['campus_id' => $campusId]);

                $text = '';

            case 1:
                $campusId = $conversation->get('campus_id');

                // Check if the selected campus requires selecting faculty when generating a timetable
                if ($this->icressService->isFacultyRequired($campusId)) {

                    // Check if from previous step
                    if ($text === '') {
                        $conversation->persist(['step' => 1]);

                        return $this->sendFaculties();
                    }

                    $facultyId = $this->icressService->getFacultyIdByName($text);

                    if (!$facultyId) {
                        return $this->replyToChat(__('uitmtimetable.telegram_bot.invalid_faculty'));
                    }

                    // Save the selected faculty in the conversation context
                    $conversation->persist(['faculty_id' => $facultyId]);
                }

                $text = '';

            case 2:
                // Check if from previous step
                if ($text === '') {
                    $conversation->persist(['step' => 2]);

                    return $this->replyToChat(__('uitmtimetable.telegram_bot.enter_courses_code'));
                }

                if (!$this->isValidCoursesFormat($text)) {
                    return $this->replyToChat(__('uitmtimetable.telegram_bot.invalid_courses'));
                }

                // Save the courses code in the conversation context
                $conversation->persist(['courses' => $text]);

            case 3:
                $campusId  = $conversation->get('campus_id');
                $facultyId = $conversation->get('faculty_id');
                $courses   = $conversation->get('courses');

                $conversation->end();

                return $this->sendCourseTimetable($campusId, $facultyId, $courses);
        }

        return Request::emptyResponse();
    }

    /**
     * Reply to the user with a list of campuses to be selected.
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    private function sendCampuses()
    {
        $campuses = $this->icressService->getCampuses();
        $keyboard = new Keyboard(...array_values($campuses));

        $replyMarkup = $keyboard->setIsPersistent(true)
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->setInputFieldPlaceholder(__('uitmtimetable.telegram_bot.select_campus'))
            ->setSelective(true);

        return $this->replyToChat(__('uitmtimetable.telegram_bot.select_campus'), [
            'reply_markup' => $replyMarkup,
        ]);
    }

    /**
     * Reply to the user with a list of faculties to be selected.
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    private function sendFaculties()
    {
        $faculties = $this->icressService->getFaculties();
        $keyboard = new Keyboard(...array_values($faculties));

        $replyMarkup = $keyboard->setIsPersistent(true)
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->setInputFieldPlaceholder(__('uitmtimetable.telegram_bot.select_faculty'))
            ->setSelective(true);

        return $this->replyToChat(__('uitmtimetable.telegram_bot.select_faculty'), [
            'reply_markup' => $replyMarkup,
        ]);
    }

    /**
     * Reply to the user with generated timetable.
     *
     * @param  string  $campusId
     * @param  string  $facultyId
     * @param  string  $courses
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    private function sendCourseTimetable($campusId, $facultyId, $courses)
    {
        $courses = $this->mapCourseWithGroup($courses);

        $coursesTimetable = $this->icressService->getFormattedCoursesTimetable($campusId, $facultyId, $courses);

        if ($coursesTimetable) {
            return $this->sendView('telegram.commands.generate.timetable', compact('coursesTimetable'));
        }

        return $this->sendView('telegram.commands.generate.timetable-not-found');
    }

    /**
     * Returns true if provided courses is in a valid format.
     *
     * @param  string|array  $courses
     * @return boolean
     */
    private function isValidCoursesFormat($courses)
    {
        $courses = $this->mapCourseWithGroup($courses);

        if ($courses) {
            return true;
        }

        return false;
    }

    /**
     * Returns an array of course code and group.
     *
     * @param  string|array  $courses
     * @return array
     */
    private function mapCourseWithGroup($courses)
    {
        if (!is_array($courses)) {
            $courses = explode("\n", $courses);
        }

        $courseCodeWithGroup = array();

        foreach ($courses as $course) {
            if (strpos($course, ' - ')) {
                list($key, $value) = array_map('trim', explode(' - ', $course));

                $courseCodeWithGroup[$key] = $value;
            }
        }

        return $courseCodeWithGroup;
    }
}
