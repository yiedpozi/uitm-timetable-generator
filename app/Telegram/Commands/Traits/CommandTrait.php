<?php

namespace App\Telegram\Commands\Traits;

trait CommandTrait
{
    /**
     * Reply to the user with HTML content from Blade template.
     *
     * @param  string  $view
     * @param  array   $data
     * @return \Longman\TelegramBot\Entities\ServerResponse
     */
    public function sendView(string $view, array $data = array())
    {
        $html = view($view, $data)->render();

        return $this->replyToChat($html, [
            'parse_mode' => 'html',
        ]);
    }
}
