<?php

namespace App;

enum AssessmentType: string
{
    case ClassWork = 'class_work';
    case Homework = 'homework';
    case Quiz = 'quiz';
    case Test = 'test';
    case MidTerm = 'mid_term';
    case Examination = 'examination';
    case Project = 'project';
    case Other = 'other';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
