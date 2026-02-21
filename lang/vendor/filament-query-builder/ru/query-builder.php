<?php

return [

    'operators' => [

        'date' => [

            'unit_labels' => [
                'second' => 'Секунды',
                'minute' => 'Минуты',
                'hour' => 'Часы',
                'day' => 'Дни',
                'week' => 'Недели',
                'month' => 'Месяцы',
                'quarter' => 'Кварталы',
                'year' => 'Годы',
            ],

            'presets' => [
                'past_decade' => 'Прошлое десятилетие',
                'past_5_years' => 'Прошлые 5 лет',
                'past_2_years' => 'Прошлые 2 года',
                'past_year' => 'Прошлый год',
                'past_6_months' => 'Прошлые 6 месяцев',
                'past_quarter' => 'Прошлый квартал',
                'past_month' => 'Прошлый месяц',
                'past_2_weeks' => 'Прошлые 2 недели',
                'past_week' => 'Прошлая неделя',
                'past_hour' => 'Прошлый час',
                'past_minute' => 'Прошлая минута',
                'this_decade' => 'Это десятилетие',
                'this_year' => 'Этот год',
                'this_quarter' => 'Этот квартал',
                'this_month' => 'Этот месяц',
                'today' => 'Сегодня',
                'this_hour' => 'Этот час',
                'this_minute' => 'Эта минута',
                'next_minute' => 'Следующая минута',
                'next_hour' => 'Следующий час',
                'next_week' => 'Следующая неделя',
                'next_2_weeks' => 'Следующие 2 недели',
                'next_month' => 'Следующий месяц',
                'next_quarter' => 'Следующий квартал',
                'next_6_months' => 'Следующие 6 месяцев',
                'next_year' => 'Следующий год',
                'next_2_years' => 'Следующие 2 года',
                'next_5_years' => 'Следующие 5 лет',
                'next_decade' => 'Следующее десятилетие',
                'custom' => 'Произвольный',
            ],

            'form' => [

                'mode' => [

                    'label' => 'Тип даты',

                    'options' => [
                        'absolute' => 'Конкретная дата',
                        'relative' => 'Скользящий период',
                    ],

                ],

                'preset' => [
                    'label' => 'Период',
                ],

                'relative_value' => [
                    'label' => 'Количество',
                ],

                'relative_unit' => [
                    'label' => 'Единица времени',
                ],

                'tense' => [

                    'label' => 'Время',

                    'options' => [
                        'past' => 'Прошлое',
                        'future' => 'Будущее',
                    ],

                ],

            ],

        ],

    ],

];
