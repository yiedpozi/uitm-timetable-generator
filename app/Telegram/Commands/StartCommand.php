<?php

namespace App\Telegram\Commands;

use App\Telegram\Commands\Traits\CommandTrait;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

class StartCommand extends UserCommand
{
    use CommandTrait;

    protected $name = 'start';
    protected $description = 'Start UiTM Timetable Telegram bot.';
    protected $usage = '/start';
    protected $version = '1.0.0';

    /**
     * Execute command.
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute(): ServerResponse
    {
        return $this->sendView('telegram.commands.start');
    }
}
