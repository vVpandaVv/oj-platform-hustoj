<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Datasets;

use OjCodeLab\Database\Dataset_Repository;
use OjCodeLab\Support\Csv_Encoding;
use WP_Error;

/**
 * Bundles small, well-known teaching datasets as "추천 데이터" so a
 * teacher has something that works immediately instead of starting from an
 * empty list. Each sample has a stable `sample_key` (Database\Installer's
 * `oj_code_lab_datasets.sample_key` column) — that, not "is the table
 * empty", is what decides whether a given sample still needs inserting, so:
 *
 * - maybe_seed() (called from Database\Installer::install(), i.e. on first
 *   activation) inserts every sample that isn't there yet — on a fresh
 *   install that's all of them.
 * - restore_missing() does exactly the same insert-if-absent pass, but is
 *   exposed as a deliberate teacher action (Rest\Controllers\
 *   Dataset_Controller::restore_samples(), a "복구" button in the console)
 *   rather than running automatically — a teacher who intentionally
 *   deletes a sample they don't want should not see it silently reappear
 *   after a routine plugin update, only when they explicitly ask to
 *   restore defaults.
 *
 * File sources (see resources/sample-datasets/):
 * - anagrams/anscombe/attention/brain_networks/car_crashes/diamonds/dots/
 *   dowjones/exercise/flights/fmri/geyser/glue/healthexp/iris/mpg/
 *   penguins/planets/seaice/taxis/tips/titanic: small CSVs from
 *   https://github.com/mwaskom/seaborn-data, published by the seaborn
 *   maintainer specifically as ready-to-teach-with example tables (the
 *   same repo seaborn's own `load_dataset()` pulls from). Per that repo's
 *   README: titanic → Kaggle Titanic competition, tips → R `reshape2`
 *   package, iris → UCI ML Repository (see that README for the rest).
 * - seoul_bike_rental_gu / seoul_covid19 / the kosis_* files / krx_listed_companies:
 *   derived from Korean public data (서울 열린데이터광장, KOSIS 국가통계
 *   포털, 한국거래소 상장 정보) via teaching repos by corazzon (조수현).
 *   Per-file redistribution terms remain pending; see docs/THIRD_PARTY.md.
 *   Declared upstream repositories:
 *   github.com/corazzon/OpenDataWrangling, COVID-19-eda, kostat-eda,
 *   finance-data-analysis. Re-encoded from their original EUC-KR/CP949 to
 *   UTF-8 when bundled (see Support\Csv_Encoding — this is exactly the
 *   encoding problem that helper exists to solve for teacher-added
 *   datasets too).
 *
 * Deliberately NOT bundled: the 청와대 국민청원 (Blue House petitions)
 * dataset from corazzon/petitions — even its 5%-sampled file is ~24.5MB
 * (over Support\Dataset_Limits' default cap), and individual petitions
 * sometimes contain petitioners' own personal contact details in the free
 * text, which is a poor fit for a default classroom dataset even though the
 * dataset itself is openly licensed (KOGL 제1유형).
 */
final class Sample_Dataset_Seeder {
    private const SAMPLES = array(
        // seaborn-data
        array('key' => 'titanic', 'file' => 'titanic.csv', 'label' => '타이타닉 생존자', 'icon' => '🚢'),
        array('key' => 'tips', 'file' => 'tips.csv', 'label' => '레스토랑 팁', 'icon' => '🍽️'),
        array('key' => 'iris', 'file' => 'iris.csv', 'label' => '붓꽃(iris) 품종', 'icon' => '🌸'),
        array('key' => 'penguins', 'file' => 'penguins.csv', 'label' => '팔머펭귄 측정치', 'icon' => '🐧'),
        array('key' => 'flights', 'file' => 'flights.csv', 'label' => '월별 항공 승객수', 'icon' => '✈️'),
        array('key' => 'mpg', 'file' => 'mpg.csv', 'label' => '자동차 연비', 'icon' => '🚗'),
        array('key' => 'car_crashes', 'file' => 'car_crashes.csv', 'label' => '미국 주별 교통사고', 'icon' => '🚦'),
        array('key' => 'anscombe', 'file' => 'anscombe.csv', 'label' => '앤스컴의 4중주', 'icon' => '📐'),
        array('key' => 'healthexp', 'file' => 'healthexp.csv', 'label' => '국가별 의료비 지출', 'icon' => '💊'),
        array('key' => 'taxis', 'file' => 'taxis.csv', 'label' => '뉴욕 택시 운행 기록', 'icon' => '🚕'),
        array('key' => 'seaice', 'file' => 'seaice.csv', 'label' => '북극 해빙 면적', 'icon' => '🧊'),
        array('key' => 'dowjones', 'file' => 'dowjones.csv', 'label' => '다우존스 지수', 'icon' => '📈'),
        array('key' => 'geyser', 'file' => 'geyser.csv', 'label' => '간헐천 분출 데이터', 'icon' => '⛲'),
        array('key' => 'diamonds', 'file' => 'diamonds.csv', 'label' => '다이아몬드 가격', 'icon' => '💎'),
        array('key' => 'planets', 'file' => 'planets.csv', 'label' => '외계행성 발견 기록', 'icon' => '🪐'),
        array('key' => 'attention', 'file' => 'attention.csv', 'label' => '집중력 실험', 'icon' => '🎯'),
        array('key' => 'fmri', 'file' => 'fmri.csv', 'label' => 'fMRI 신경과학 데이터', 'icon' => '🧠'),
        array('key' => 'exercise', 'file' => 'exercise.csv', 'label' => '운동·식단 실험', 'icon' => '🏃'),
        array('key' => 'glue', 'file' => 'glue.csv', 'label' => 'NLP 모델 성능 비교', 'icon' => '🤖'),
        array('key' => 'dots', 'file' => 'dots.csv', 'label' => '심리물리학 실험', 'icon' => '👀'),
        array('key' => 'brain_networks', 'file' => 'brain_networks.csv', 'label' => '뇌 연결성 데이터', 'icon' => '🕸️'),
        array('key' => 'anagrams', 'file' => 'anagrams.csv', 'label' => '애너그램 실험', 'icon' => '🔤'),
        // Korean public data
        array('key' => 'seoul_bike_rental_gu', 'file' => 'seoul_bike_rental_gu.csv', 'label' => '서울 공공자전거 대여소(구별)', 'icon' => '🚲'),
        array('key' => 'seoul_covid19', 'file' => 'seoul_covid19.csv', 'label' => '서울시 코로나19 확진자 현황', 'icon' => '😷'),
        array('key' => 'kosis_customer_price', 'file' => 'kosis_customer_price.csv', 'label' => '소비자물가지수(KOSIS)', 'icon' => '💰'),
        array('key' => 'kosis_airline_company', 'file' => 'kosis_airline_company.csv', 'label' => '항공사별 국내선 통계(KOSIS)', 'icon' => '✈️'),
        array('key' => 'kosis_economic_index', 'file' => 'kosis_economic_index.csv', 'label' => '경제지표 모음(KOSIS)', 'icon' => '📊'),
        array('key' => 'krx_listed_companies', 'file' => 'krx_listed_companies.csv', 'label' => '한국거래소 상장기업 목록', 'icon' => '🏢'),
        array('key' => 'kosis_job_seeker_giveup', 'file' => 'kosis_job_seeker_giveup.csv', 'label' => '구직단념자 통계(KOSIS)', 'icon' => '💼'),
    );

    public static function maybe_seed(): void {
        self::insert_missing();
    }

    /**
     * @return int how many samples were (re-)inserted
     */
    public static function restore_missing(): int|WP_Error {
        return self::insert_missing();
    }

    private static function insert_missing(): int|WP_Error {
        $keys = Dataset_Repository::existing_sample_keys();
        if (is_wp_error($keys)) { return $keys; }
        $existing = array_flip($keys);
        $inserted = 0;

        foreach (self::SAMPLES as $sample) {
            if (isset($existing[$sample['key']])) {
                continue;
            }

            $path = OJ_CODE_LAB_PATH . 'resources/sample-datasets/' . $sample['file'];
            if (! is_readable($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if (false === $content) {
                continue;
            }

            $content = Csv_Encoding::normalize($content);
            if (is_wp_error($content)) { return $content; }
            $key = Upload_Store::store_bytes($content);
            if (is_wp_error($key)) {
                return $key;
            }
            $id = Dataset_Repository::create(array(
                'label' => $sample['label'],
                'icon' => $sample['icon'],
                'source' => 'upload',
                'uploadKey' => $key,
                'fileName' => $sample['file'],
                'sampleKey' => $sample['key'],
                'createdBy' => 0,
            ));
            if (is_wp_error($id)) {
                Upload_Store::delete($key);
                return $id;
            }
            $inserted++;
        }

        return $inserted;
    }
}
