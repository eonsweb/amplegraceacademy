<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('class_levels', 'description')) {
            Schema::table('class_levels', function (Blueprint $table) {
                $table->text('description')->nullable()->after('level_order');
            });
        }

        if (! Schema::hasColumn('class_subjects', 'class_level_id')) {
            Schema::table('class_subjects', function (Blueprint $table) {
                $table->foreignId('class_level_id')->nullable()->after('academic_year_id');
            });
        }

        if (! Schema::hasColumn('class_subjects', 'is_elective')) {
            Schema::table('class_subjects', function (Blueprint $table) {
                $table->boolean('is_elective')->default(false)->after('staff_id');
            });
        }

        if (! collect(Schema::getIndexes('class_subjects'))->contains('name', 'class_subjects_academic_year_id_index')) {
            Schema::table('class_subjects', function (Blueprint $table) {
                $table->index('academic_year_id');
            });
        }

        DB::table('class_subjects')->update([
            'class_level_id' => DB::raw('(select class_level_id from class_sections where class_sections.id = class_subjects.class_section_id)'),
        ]);

        $duplicates = DB::table('class_subjects')
            ->select(['academic_year_id', 'class_level_id', 'subject_id'])
            ->groupBy(['academic_year_id', 'class_level_id', 'subject_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $assignments = DB::table('class_subjects')
                ->where('academic_year_id', $duplicate->academic_year_id)
                ->where('class_level_id', $duplicate->class_level_id)
                ->where('subject_id', $duplicate->subject_id)
                ->orderByRaw('staff_id IS NULL')
                ->orderBy('id')
                ->get(['id']);

            DB::table('class_subjects')->whereIn('id', $assignments->skip(1)->pluck('id'))->delete();
        }

        $hasClassSectionForeignKey = DB::getDriverName() === 'sqlite'
            || collect(Schema::getForeignKeys('class_subjects'))
                ->contains('name', 'class_subjects_class_section_id_foreign');

        Schema::table('class_subjects', function (Blueprint $table) use ($hasClassSectionForeignKey) {
            if ($hasClassSectionForeignKey) {
                $table->dropForeign(['class_section_id']);
            }

            $table->dropUnique('class_subject_context_unique');
            $table->dropIndex(['class_section_id', 'academic_year_id']);
            $table->dropColumn('class_section_id');
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->unsignedBigInteger('class_level_id')->nullable(false)->change();
            $table->foreign('class_level_id')->references('id')->on('class_levels')->restrictOnDelete();
            $table->unique(['academic_year_id', 'class_level_id', 'subject_id'], 'class_subject_context_unique');
            $table->index(['class_level_id', 'academic_year_id']);
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropIndex(['academic_year_id']);
        });

        Schema::drop('class_sections');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('class_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_level_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['class_level_id', 'name']);
        });

        $now = now();
        $classSections = DB::table('class_levels')->orderBy('id')->pluck('id')->map(fn (int $classLevelId): array => [
            'class_level_id' => $classLevelId,
            'name' => 'Default',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($classSections !== []) {
            DB::table('class_sections')->insert($classSections);
        }

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->foreignId('class_section_id')->nullable()->after('class_level_id');
            $table->index('academic_year_id');
        });

        DB::table('class_subjects')->update([
            'class_section_id' => DB::raw('(select id from class_sections where class_sections.class_level_id = class_subjects.class_level_id limit 1)'),
        ]);

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropForeign(['class_level_id']);
            $table->dropUnique('class_subject_context_unique');
            $table->dropIndex(['class_level_id', 'academic_year_id']);
            $table->dropColumn(['class_level_id', 'is_elective']);
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->unsignedBigInteger('class_section_id')->nullable(false)->change();
            $table->foreign('class_section_id')->references('id')->on('class_sections')->restrictOnDelete();
            $table->unique(['academic_year_id', 'class_section_id', 'subject_id'], 'class_subject_context_unique');
            $table->index(['class_section_id', 'academic_year_id']);
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropIndex(['academic_year_id']);
        });

        Schema::table('class_levels', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
