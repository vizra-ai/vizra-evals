<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vizra\Evals\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(RefreshDatabase::class)->in('Feature');
