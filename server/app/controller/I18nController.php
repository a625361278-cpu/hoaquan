<?php

namespace app\controller;

use app\support\I18n;
use support\Request;
use support\Response;

class I18nController
{
    public function messages(Request $request): Response
    {
        $locale = I18n::normalizeLocale((string)($request->get('locale') ?: $request->get('lang') ?: ''));
        return json([
            'code' => 0,
            'msg' => I18n::t('api.common.ok', [], $locale),
            'data' => [
                'locale' => $locale,
                'messages' => I18n::messages($locale),
            ],
        ]);
    }
}
