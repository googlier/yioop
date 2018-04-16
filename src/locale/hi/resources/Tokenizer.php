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
 * @author Chris Pollett chris@pollett.org
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\locale\hi\resources;

/**
 * Hindi specific tokenization code. In particular, it has a stemmer,
 * The stemmer is my stab at porting Ljiljana Dolamic (University of Neuchatel,
 * www.unine.ch/info/clef/) Java stemming algorithm:
 * http://members.unine.ch/jacques.savoy/clef/HindiStemmerLight.java.txt
 * Here given a word, its stem is that part of the word that
 * is common to all its inflected variants. For example,
 * tall is common to tall, taller, tallest. A stemmer takes
 * a word and tries to produce its stem.
 *
 * @author Chris Pollett
 */
class Tokenizer
{
    /**
     * Words we don't want to be stemmed
     * @var array
     */
    public static $no_stem_list = [];
    /**
     * Stub function which could be used for a word segmenter.
     * Such a segmenter on input thisisabunchofwords would output
     * this is a bunch of words
     *
     * @param string $pre_segment  before segmentation
     * @return string should return string with words separated by space
     *     in this case does nothing
     */
    public static function segment($pre_segment)
    {
        return $pre_segment;
    }
    /**
     * Removes the stop words from the page (used for Word Cloud generation)
     *
     * @param string $page the page to remove stop words from.
     * @return string $page with no stop words
     */
    public static function stopwordsRemover($page)
    {
        $stop_words = [
            "पर ", "इन ", "वह ", "यिह ", "वुह ", "जिन्हें", "जिन्हों",
            "तिन्हें", "तिन्हों", "किन्हों", "किन्हें", "इत्यादि", "द्वारा",
            "इन्हें", "इन्हों", "उन्हों", "बिलकुल", "निहायत", "ऱ्वासा",
            "इन्हीं", "उन्हीं", "उन्हें", "इसमें", "जितना", "दुसरा",
            "कितना", "दबारा", "साबुत", "वग़ैरह", "दूसरे", "कौनसा", "लेकिन",
            "होता", "करने", "किया", "लिये", "अपने", "नहीं", "दिया", "इसका",
            "करना", "वाले", "सकते", "इसके", "सबसे", "होने", "करते", "बहुत",
            "वर्ग", "करें", "होती", "अपनी", "उनके", "कहते", "होते", "करता",
            "उनकी", "इसकी", "सकता", "रखें", "अपना", "उसके", "जिसे",
            "तिसे", "किसे", "किसी", "काफ़ी", "पहले", "नीचे", "बाला", "यहाँ",
            "जैसा", "जैसे", "मानो", "अंदर", "भीतर", "पूरा", "सारा", "होना",
            "उनको", "वहाँ", "वहीं", "जहाँ", "जीधर","उनका", "इनका", "﻿के",
            "हैं", "गया", "बनी", "एवं", "हुआ", "साथ", "बाद", "लिए", "कुछ",
            "कहा", "यदि", "हुई", "इसे", "हुए", "अभी", "सभी", "कुल", "रहा",
            "रहे", "इसी", "उसे", "जिस", "जिन", "तिस", "तिन", "कौन", "किस",
            "कोई", "ऐसे", "तरह", "किर", "साभ", "संग", "यही", "बही", "उसी",
            "फिर", "मगर", "का", "एक", "यह", "से", "को", "इस", "कि", "जो",
            "कर", "मे", "ने", "तो", "ही", "या", "हो", "था", "तक", "आप", "ये",
            "थे", "दो", "वे", "थी", "जा", "ना", "उस", "एस", "पे", "उन", "सो",
            "भी", "और", "घर", "तब", "जब", "अत", "व", "न"
        ];
        $page = preg_replace('/\b('.implode('|',$stop_words).')\b/u', '',
            $page);
        return $page;
    }
    /**
     * Computes the stem of an Hindi word
     *
     * @param string $word the string to stem
     * @return string the stem of $word
     */
    public static function stem($word)
    {
        if (in_array($word, self::$no_stem_list)) {
            return $word;
        }
        $word = self::removeSuffix($word);
        return $word;
    }
    /**
     * Removes common Hindi suffixes
     *
     * @param string $word to remove suffixes from
     * @return string result of suffix removal
     */
    private static function removeSuffix($word)
    {
        $length = mb_strlen($word);
        if ($length > 5) {
            $last_three = mb_substr($word, -3);
            if (in_array($last_three, ["िया", "ियो"])) {
                $word = mb_substr($word, 0, -3);
                return $word;
            }
        }
        if ($length > 4) {
            $last_two = mb_substr($word, -2);
            if (in_array($last_two, ["ाए", " ाओ", " ुआ", " ुओ",
                "ये", " ेन", " ेण", " ीय", "टी", "ार", "ाई"])) {
                $word = mb_substr($word, 0, -2);
                return $word;
            }
        }
        if ($length > 3) {
            $last_one = mb_substr($word, -1);
             if (in_array($last_one, [" ा", " े", " ी", " ो", "ि ",
                "अ"])) {
                $word = mb_substr($word, 0, -1);
                return $word;
            }
        }
        return $word;
    }
    /**
     * The method takes as input a phrase and returns a string with each
     * term tagged with a part of speech.
     *
     * @param string $phrase text to add parts speech tags to
     * @param bool $with_tokens whether to include the terms and the tags
     *      in the output string or just the part of speech tags
     * @return string $tagged_phrase which is a string of format term~pos
     */
    public static function tagPartsOfSpeechPhrase($phrase, $with_tokens = true)
    {
        $tagged_tokens = self::tagTokenizePartOfSpeech($phrase);
        $tagged_phrase  = self::taggedPartOfSpeechTokensToString(
            $tagged_tokens, $with_tokens);
        return $tagged_phrase;
    }
    /**
     * Uses the lexicon to assign a tag to each token and then uses a rule
     * based approach to assign the most likely of tags to each token
     *
     * @param string $text input phrase which is to be tagged
     * @return string $result which is an array of token => tag
     */
    public static function tagTokenizePartsofSpeech($text)
    {
        static $dictionary = [];
        if (empty($dictionary)) {
            $serial_lex_file = C\LOCALE_DIR .
                "/hi/resources/serial_lexicon.txt";
            if (file_exists($serial_lex_file)) {
                $dictionary = unserialize(file_get_contents($serial_lex_file));
            } else {
                $lines = gzfile(C\LOCALE_DIR . "/hi/resources/lexicon.txt.gz");
                foreach ($lines as $line) {
                    $tags = explode(',', trim($line, ' '));
                    $dictionary[array_shift($tags)] = $tags;
                }
                $dictionary_string = serialize($dictionary);
                file_put_contents($serial_lex_file, $dictionary_string);
                chmod(0777, $serial_lex_file);
            }
        }
        preg_match_all("/[\w\d]+/", $text, $matches);
        $nouns = ['NN','NNP','NNS'];
        $verbs = ['VBZ','VBD','VBN'];
        $tokens = explode(' ', $text);
        $result = [];
        $tag_list = [];
        $i = 0;
        foreach ($tokens as $token) {
            /**
             * Tag the tokens as found in the Lexicon, else use default tag as
             * UNKNOWN
             */
            $current = ['token' => $token, 'tag' => 'UNKNOWN'];
            if (!empty($dictionary[$token])) {
                $tag_list = $dictionary[$token];
                $current['tag'] = $tag_list[0];
            }
            /**
             * NOUN IDENTIFICATION
             * RULE 1: If the previous word tagged is a Adjective / Pronoun /
             * Postposition then the current word is likely to be a noun
             */
            if ($previous['tag'] == 'JJ' ||
                $previous['tag'] == 'PRP' ||
                $previous['tag'] == 'POST_POS') {
                $current['tag'] = 'NN';
            }
            /**
             * NOUN INDENTIFICATION
             * RULE 2: If the current word is a verb then the previous word is
             * likely to be a noun
             */
            if (in_array($current['tag'], $verbs)) {
                $previous['tag'] = 'NN';
                $result[$i-1] = $previous;
            }
            /**
             * NOUN INDENTIFICATION
             * RULE 3: If the current tag is a noun then next / previous is
             * likely to be a noun
             */
            /**
             * DEMONSTRATIVE IDENTIFICATION
             * RULE 1: If the current and previous words are tagged as pronouns
             * then the previous word is likley to be a demonstrative
             */
            if ($current['tag'] == 'PRP' && $previous['tag'] == 'PRP') {
                $previous['tag'] = 'DEM';
                $result[$i-1] = $previous;
            }
            /**
             * DEMONSTRATIVE IDENTIFICATION
             * RULE 2: If current word is a noun and previous word is a pronoun
             * then the current word is liklely to be demonstrative
             */
            if ($current['tag'] == 'NN' && $previous['tag'] == 'PRP') {
                $current['tag'] = 'DEM';
            }
            /**
             * PRONOUN IDENTIFICATION
             * RULE 1: If the previous word is unknown and cuurent word is a
             * noun then the previous word is most likely to be a pronoun
             */
            if ($previous['tag'] == 'UNKNOWN' && $current['tag'] == 'NN') {
                $previous['tag'] = 'PRP';
                $result[$i - 1] = $previous;
            }
            /**
             * NAME Identification
             * RULE: If we get two words which are untagged the most probably
             * they form a name and will be tagged as noun
             */
            if ($previous['tag'] == 'UNKNOWN' && $current['tag'] == 'UNKNOWN') {
                $current['tag'] = 'NN';
                $previous['tag'] = 'NN';
                $result[$i - 1] = $previous;
            }
           /**
            * ADJECTIVE IDENTIFCATION
            * RULE: If the word ends with <tar>, <tam>, <thik> then we tag it
            * as a Adjective
            */
            /**
             * VERB IDENTIFICATION
             * RULE: If the current word is tagged as Auxilary verb and
             * previous word is tagged as Unknown then most likely that the
             * previous word is a verb
             */
            if ($current['tag'] == 'VAUX' && $previous['tag'] == 'UNKNOWN') {
                $previous['tag'] = 'VB';
                $result[$i - 1] = $previous;
            }
            $result[$i] = $current;
            $i++;
            $previous = $current;
            $previous_token = $token;
        }
        return $result;
    }
    /**
     * This method is used to simplify the different tags of speech to a
     * common form
     *
     * @param array $tagged_tokens which is an array of tokens assigned tags.
     * @param bool $with_tokens whether to include the terms and the tags
     *      in the output string or just the part of speech tags
     * @return string $tagged_phrase which is a string fo form token~pos
     */
    public static function taggedPartOfSpeechTokensToString($tagged_tokens,
        $with_tokens = true)
    {
        $tagged_phrase = [];
        $with_tokens = $with_tokens;
        $simplified_parts_of_speech = [
          "NN" => "NN", "NNS" => "NN", "NNP" => "NN", "NNPS" => "NN",
          "PRP" => "NN", 'PRP$' => "NN", "WP" => "NN",
          "VB" => "VB", "VBD" => "VB", "VBN" => "VB", "VBP" => "VB",
          "VBZ" => "VB",
          "JJ" => "AJ", "JJR" => "AJ", "JJS" => "AJ",
          "RB" => "AV", "RBR" => "AV", "RBS" => "AV", "WRB" => "AV"
        ];
        foreach ($tagged_tokens as $t) {
            $tag = trim($t['tag']);
            $tag = (isset($simplified_parts_of_speech[$tag])) ?
                   $simplified_parts_of_speech[$tag] : $tag;
            $token = ($with_tokens) ? $t['token'] . "~" : "";
            $tagged_phrase .= $token . $tag .  " ";
        }
        return $tagged_phrase;
    }
}
