<?php
/**
 * SeekQuarry/Yioop --
 * Open Source Pure PHP Search Engine, Crawler, and Indexer
 *
 * Copyright (C) 2009 - 2017  Chris Pollett chris@pollett.org
 *
 * LICENSE:
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * END LICENSE
 *
 * @author Chris Pollett (chris@pollett.org)
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\library\summarizers;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\library as L;
use seekquarry\yioop\library\CrawlConstants;
use seekquarry\yioop\library\PhraseParser;
use seekquarry\yioop\library\processors\PageProcessor;

/**
 * Class which may be used by the processors to get a summary for a text
 * document that may later be used for indexing. Generate a summary based
 * on it closeness to the average sentence. It also weights sentences based
 * on the CMS that produced it. It also generates a word cloud for a document.
 * @author Charles Bocage (charles.bocage@sjsu.edu)
 */
class CentroidWeightedSummarizer extends Summarizer
{
    /**
     * Number of bytes in a sentence before it is considered long
     * We use strlen rather than mbstrlen. This might actually be
     * a better metric of the potential of a sentence to have info.
     */
    const LONG_SENTENCE_LEN = 50;
    /**
     * Number of sentences in a document before only consider longer
     * sentences in centroid
     */
    const LONG_SENTENCE_THRESHOLD = 100;
    /**
     * Number of distinct terms to use in generating summary
     */
    const MAX_DISTINCT_TERMS = 1000;
    /**
     * Number of words in word cloud
     */
    const WORD_CLOUD_LEN = 5;
    /**
     * Number of nonzero centroid components
     */
    const CENTROID_COMPONENTS = 50;
    /**
     * whether to output the results to the disk or not
     */
    const OUTPUT_TO_FILE = false;
    /**
     * The full disk location to save the result to
     */
    const OUTPUT_FILE_PATH = "/temp/centroid_weighted_summarizer_result.txt";
    /**
     * Generate a summary based on it closeness to the average sentence.
     * It also weights sentences based on the CMS that produced it.
     *
     * @param object $dom document object model of page to summarize
     * @param string $page complete raw page to generate the summary from.
     * @param string $lang language of the page to decide which stop words to
     *     call proper tokenizer.php of the specified language.
     *
     * @return array array of summary and word cloud
     */
    public static function getSummary($dom, $page, $lang)
    {
        $page = self::pageProcessing($page);
        /* Format the document to remove characters other than periods and
           alphanumerics.
        */
        $formatted_doc = self::formatDoc($page);
        $stop_obj = PhraseParser::getTokenizer($lang);
        /* Splitting into sentences */
        $out_sentences = self::getSentences($page);
        $sentences = self::removeStopWords($out_sentences, $stop_obj);
        $sentence_array = self::splitSentences($sentences, $lang);
        $terms = $sentence_array[0];
        $tf_per_sentence = $sentence_array[1];
        $tf_per_sentence_normalized = $sentence_array[2];
        $tf_average_sentence =
            self::getAverageSentence($tf_per_sentence_normalized);
        $tf_dot_product_per_sentence =
            self::getDotProduct($tf_per_sentence_normalized,
            $tf_average_sentence);
        usort($tf_dot_product_per_sentence, 'self::sortInAscendingOrder');
        $summary = self::getSummaryFromProducts($tf_dot_product_per_sentence,
            $out_sentences, $lang);
        $n = count($out_sentences);
        $terms = array_filter($terms);
        $terms_counts = array_count_values($terms);
        arsort($terms_counts);
        $terms_counts = array_slice($terms_counts, 0,
            self::MAX_DISTINCT_TERMS);
        $terms = array_unique(array_keys($terms_counts));
        $t = count($terms);
        if ($t == 0) {
            return ["", ""];
        }
        /* Initialize Nk [Number of sentences the term occurs] */
        $nk = [];
        $nk = array_fill(0, $t, 0);
        $nt = [];
        /* Count TF for each word */
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $t; $j++) {
                if (strpos($sentences[$i], $terms[$j]) !== false) {
                    $nk[$j]++;
                }
            }
        }
        /* Calculate weights of each term for every sentence */
        $w = [];
        $idf = [];
        $idf_temp = 0;
        for ($k = 0; $k < $t; $k++) {
            if ($nk[$k] == 0) {
                $idf_temp = 0;
                $tmp = 0;
            } else {
                $idf_temp = $n / $nk[$k];
                $tmp = log($idf_temp);
            }
            $idf[$k] = $tmp;
        }
        /* Count TF for finding centroid */
        $wc = [];
        $max_nt = -1;
        $b = "\b";
        if (in_array($lang, ["zh-CN", "ja", "ko"])) {
            $b = "";
        }
        restore_error_handler();
        for ($j = 0; $j < $t; $j++) {
            $quoted = preg_quote($terms[$j]);
            $nt = @preg_match_all("/$b" . $quoted . "$b/", $formatted_doc,
                $matches); //$matches included for backwards compatibility
            $wc[$j] = $nt * $idf[$j];
            if (is_nan($wc[$j]) || is_infinite($wc[$j])) {
                $wc[$j] = 0;
            }
        }
        set_error_handler(C\NS_LIB . "yioop_error_handler");
        /* Calculate centroid */
        arsort($wc);
        $centroid = array_slice($wc, 0, self::CENTROID_COMPONENTS, true);
        /* Initializing centroid weight array by 0 */
        $wc = array_fill(0, $t, 0);
        /* Word cloud */
        $i = 0;
        $word_cloud = [];
        foreach ($centroid as $key => $value) {
            $wc[$key] = $value;
            if ($i < self::WORD_CLOUD_LEN) {
                $word_cloud[$i] = $terms[$key];
            }
            $i++;
        }
        /* Summary of text summarization */
        return [$summary, $word_cloud];
    }
    /**
     * Calculates how many sentences to put in the summary to match the
     * MAX_DESCRIPTION_LEN.
     *
     * @param array $sentences sentences in doc in their original order
     * @param array $sim associative array of sentence-number-in-doc =>
     *      similarity score to centroid (sorted from highest to lowest score).
     * @return int number of sentences
     */
    public static function summarySentenceCount($sentences, $sim)
    {
        $top = null;
        $count = 0;
        foreach ($sim as $key => $value)
        {
            if ($count < PageProcessor::$max_description_len) {
                $count += strlen($sentences[$key]);
                $top++;
            }
        }
        return $top;
    }
    /**
     * Breaks any content into sentences by splitting it on spaces or carriage
     *   returns
     * @param string $content complete page.
     * @return array array of sentences from that content.
     */
    public static function getSentences($content)
    {
        $lines = preg_split(
            '/(\.|\||\!|\?|！|？|。)\s+|(\n|\r)(\n|\r)+|\s{5}/',
            $content, 0, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $sentence = "";
        $count = 0;
        $theshold_factor = 1;
        foreach ($lines as $line) {
            $sentence .= " " . $line;
            if (strlen($line) < 2) {
                continue;
            }
            if ($count < self::LONG_SENTENCE_THRESHOLD ||
                strlen($sentence) > $theshold_factor *
                    self::LONG_SENTENCE_LEN){
                $sentence = preg_replace("/\s+/ui", " ", $sentence);
                $out[] = trim($sentence);
                $count++;
                $theshold_factor =
                    pow(1.5, floor($count/self::LONG_SENTENCE_THRESHOLD));
            }
            $sentence = "";
        }
        if (trim($sentence) != "") {
            $sentence = preg_replace("/\s+/ui", " ", $sentence);
            $out[] = trim($sentence);
        }
        return $out;
    }
    /**
     * Formats the sentences to remove all characters except words,
     *   digits and spaces
     * @param string $sent complete page.
     * @return string formatted sentences.
     */
    public static function formatSentence($sent)
    {
        $sent = trim(preg_replace('/[^\p{L}\p{N}\s]+/u',
            ' ', mb_strtolower($sent)));
        return $sent;
    }
    /**
     * Formats the document to remove carriage returns, hyphens and digits
     * as we will not be using digits in word cloud.
     * The formatted document generated by this function is only used to
     * compute centroid.
     * @param string $content formatted page.
     * @return string formatted document.
     */
    public static function formatDoc($content)
    {
        $substitute = ['/[\n\r\-]+/', '/[^\p{L}\s\.]+/u', '/[\.]+/'];
        $content = preg_replace($substitute, ' ', mb_strtolower($content));
        return $content;
    }
    /**
     * This function does an additional processing on the page
     * such as removing all the tags from the page
     * @param string $page complete page.
     * @return string processed page.
     */
    public static function pageProcessing($page)
    {
        $substitutions = ['@<script[^>]*?>.*?</script>@si',
            '/\&nbsp\;|\&rdquo\;|\&ldquo\;|\&mdash\;/si',
            '@<style[^>]*?>.*?</style>@si', '/[\^\(\)]/',
            '/\[(.*?)\]/', '/\t\n/'
        ];
        $page = preg_replace($substitutions, ' ', $page);
        $page = preg_replace('/\s{2,}/', ' ', $page);
        $new_page = preg_replace("/\<br\s*(\/)?\s*\>/", "\n", $page);
        $changed = false;
        if ($new_page != $page) {
            $changed = true;
            $page = $new_page;
        }
        $page = preg_replace("/\<\/(h1|h2|h3|h4|h5|h6|table|tr|td|div|".
            "p|address|section)\s*\>/", "\n\n", $page);
        $page = preg_replace("/\<a/", " <a", $page);
        $page = preg_replace("/\&\#\d{3}(\d?)\;|\&\w+\;/", " ", $page);
        $page = preg_replace("/\</", " <", $page);
        $page = strip_tags($page);
        if ($changed) {
            $page = preg_replace("/(\r?\n[\t| ]*){2}/", "\n", $page);
        }
        $page = preg_replace("/(\r?\n[\t| ]*)/", "\n", $page);
        $page = preg_replace("/\n\n\n+/", "\n\n", $page);
        return $page;
    }
    /**
     * Calculates an array with key terms and values their frequencies
     * based on a supplied sentence
     *
     * @param array $terms the list of all terms in the doc
     * @param array $sentence the sentences in the doc
     * @return array a two dimensional array where the word is the key and
     *      the frequency is the value
     */
    public static function getTermFrequencies($terms, $sentence)
    {
        $t = count($terms);
        $nk = [];
        $nk = array_fill(0, $t, 0);
        $nt = [];
        for ($j = 0; $j < $t; $j++) {
            $nk[$j] += preg_match_all("/\b" . preg_quote($terms[$j],'/') .
                "\b/iu", $sentence, $matches);
        }
        $term_frequencies = [];
        for ($i = 0; $i <  count($nk); $i++ ) {
            $term_frequencies[$terms[$i]] = $nk[$i];
        }
        return $term_frequencies;
    }
    /**
     * Normalize the term frequencies based on the sum of the squares.
     * @param array $term_frequencies the array with the terms as the key
     *      and its frequency as the value
     * @return array array of term frequencies normalized
     */
    public static function normalizeTermFrequencies($term_frequencies)
    {
        $sum_of_squares = 0;
        $result_sum = 0;
        if (count($term_frequencies) == 0) {
            $result = [];
        } else {
            foreach ($term_frequencies as $k => $v) {
                $sum_of_squares += ($v * $v);
            }
            $square_root = sqrt($sum_of_squares);
            foreach ($term_frequencies as $k => $v) {
                if ($square_root == 0) {
                    $result[$k] = 0;
                } else {
                    $result[$k] = ($v / $square_root);
                }
            }
            foreach ($result as $k => $v) {
                $result_sum += $v;
            }
        }
        return $result;
    }
    /**
     * Get the average sentence by adding up the values from each column and
     * dividing it by the rows in the array.
     * @param array $term_frequencies_normalized the array with the terms as
     *      the key and its normalized frequency as the value
     * @return array array of frequencies averaged
     */
    public static function getAverageSentence($term_frequencies_normalized)
    {
        $result = [];
        if (count($term_frequencies_normalized) != 0) {
            foreach ($term_frequencies_normalized as $k => $v) {
                foreach ($v as $l => $w) {
                    if (count($result) == 0) {
                        $result[$l] = $w;
                    } else {
                        if (@array_key_exists($l, $result)) {
                            $result[$l] = $result[$l] + $w;
                        } else {
                            $result[$l] = $w;
                        }
                    }
                }
            }
            $count = count($term_frequencies_normalized);
            foreach ($result as $k => $v) {
                $result[$k] = ($v / $count);
            }
        }
        return $result;
    }
    /**
     * Get the dot product of the normalized array and the average sentence
     * @param array $term_frequencies_normalized the array with the terms as
     *      the key and its normalized frequency as the value
     * @param array $average_sentence an array of each words average
     *      frequency value
     * @return array array of frequencies averaged
     */
    public static function getDotProduct($term_frequencies_normalized,
        $average_sentence)
    {
            $result = [];
            $count = 0;
            foreach ($term_frequencies_normalized as $k => $v) {
                $tempResult = 0;
                foreach ($v as $l => $w) {
                    if (@array_key_exists($l, $average_sentence)) {
                        $tempResult = $tempResult +
                            ($average_sentence[$l] * $w);
                    }
                }
                $result[$count] = $tempResult;
                $count++;
            }
            return $result;
    }
    /**
     * Compare the two values and return if b is greater than a
     * @param string $a the first value to compare
     * @param string $b the second value to compare
     * @return boolean if b is greater than a
     */
    public static function sortInAscendingOrder($a, $b)
    {
        return $b > $a ? 1 : -1;
    }
    /**
     * Returns a new array of sentences without the stop words
     * @param array $sentences the array of sentences to process
     * @param object $stop_obj the class that has the stopworedRemover method
     * @return array a new array of sentences without the stop words
     */
    public static function removeStopWords($sentences, $stop_obj)
    {
        $n = count($sentences);
        $result = [];
        if ($stop_obj && method_exists($stop_obj, "stopwordsRemover")) {
            for ($i = 0; $i < $n; $i++ ) {
                $result[$i] = $stop_obj->stopwordsRemover(
                    self::formatDoc($sentences[$i]));
             }
        } else {
            $result = $sentences;
        }
        return $result;
    }
    /**
     * Split up the sentences and return an array with all of the needed parts
     * @param array $sentences the array of sentences to process
     * @param string $lang the current locale
     * @return array an array with all of the needed parts
     */
    public static function splitSentences($sentences, $lang)
    {
        $result = [];
        $terms = [];
        $tf_index = 0;
        $tf_per_sentence = [];
        $tf_per_sentence_normalized = [];
        foreach ($sentences as $sentence) {
            $temp_terms = PhraseParser::segmentSegment($sentence, $lang);
            $terms = array_merge($terms, $temp_terms);
            $tf_per_sentence[$tf_index] =
                self::getTermFrequencies($temp_terms, $sentence);
            $tf_per_sentence_normalized[$tf_index] =
                self::normalizeTermFrequencies($tf_per_sentence[$tf_index]);
            $tf_index++;
        }
        $result[0] = $terms;
        $result[1] = $tf_per_sentence;
        $result[2] = $tf_per_sentence_normalized;
        return $result;
    }
    /**
     * Split up the sentences and return an array with all of the needed parts
     * @param array $tf_dot_product_per_sentence an array that holds the dot
            product of each sentence.  It should be sorted from highest to
            lowest when it is passed to this method.
     * @param array $sentences the array of sentences to process
     * @param string $lang language of the page to decide which stop words to
     *     call proper tokenizer.php of the specified language.
     * @return string a string that represents the summary
     */
    public static function getSummaryFromProducts($tf_dot_product_per_sentence,
            $sentences, $lang)
    {
        $result = "";
        $result_length = 0;
        $i = 0;
        foreach ($tf_dot_product_per_sentence as $k => $v) {
            $sentence = PhraseParser::compressSentence($sentences[$k],
                $lang);
            if ($result_length + strlen($sentence) >
                PageProcessor::$max_description_len) {
                break;
            } else {
                $result_length += strlen($sentence);
                if ($i == 0) {
                    $i = 1;
                    $result = $sentence . ". ";
                    if (self::OUTPUT_TO_FILE) {
                        $output_file_contents = $sentence . ". ";
                    }
                } else {
                    $result .= " " . $sentence . ". ";
                    if (self::OUTPUT_TO_FILE) {
                        $output_file_contents = $output_file_contents .
                            "\r\n" . $sentence . ". ";
                    }
                }
            }
        }
        if (self::OUTPUT_TO_FILE) {
            file_put_contents(C\WORK_DIRECTORY . self::OUTPUT_FILE_PATH,
                $output_file_contents);
        }
        return $result;
    }
}
