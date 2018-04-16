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
namespace seekquarry\yioop\locale\en_US\resources;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\library\PhraseParser;
use seekquarry\yioop\library as L;

/* If you would like to use wordnet for thesaurus reordering of query results
   define the following variable in your configs/local_config.php file with
   the path to the WordNet executable.
 */
if (!C\nsdefined("WORDNET_EXEC")) {
    C\nsdefine("WORDNET_EXEC", "");
}
/**
 * This class has a collection of methods for English locale specific
 * tokenization. In particular, it has a stemmer, a stop word remover (for
 * use mainly in word cloud creation), and a part of speech tagger (if
 * thesaurus reordering used). The stemmer is my stab at implementing the
 * Porter Stemmer algorithm
 * presented http://tartarus.org/~martin/PorterStemmer/def.txt
 * The code is based on the non-thread safe C version given by Martin Porter.
 * Since PHP is single-threaded this should be okay.
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
    public static $no_stem_list = ["titanic", "programming", "fishing", 'ins',
        "blues", "factorial", "pbs"];
    /**
     * Phrases we would like yioop to rewrite before performing a query
     * @var array
     */
    public static $semantic_rewrites = [
        "ins" => 'uscis',
        "mimetype" => 'mime',
        "military" => 'armed forces',
        'full metal alchemist' => 'fullmetal alchemist',
        'bruce schnier' => 'bruce schneier',
        'dragonball' => 'dragon ball',
    ];
    /**
     * Any unique identifier corresponding to the component of a triplet which
     * can be answered using a question answer list
     * @string
     */
    public static $question_marker = "qqq";
    /**
     * List of verb-like parts of speech that might appear in lexicon file
     * @array
     */
    public static $verb_phrases = ["VB", "VBD", "VBG", "VBN", "VBP",
        "VBZ"];
    /**
     * List of noun-like parts of speech that might appear in lexicon file
     * @array
     */
    public static $noun_phrases = ["NN", "NNS", "NNP", "NNPS", "PRP"];
    /**
     * List of adjective-like parts of speech that might appear in lexicon file
     * @array
     */
    public static $adjective_phrases = ["JJ", "JJR", "JJS"];
    /**
     * storage used in computing the stem
     * @var string
     */
    private static $buffer;
    /**
     * Index of the current end of the word at the current state of computing
     * its stem
     * @var int
     */
    private static $k;
    /**
     * Index to start of the suffix of the word being considered for
     * manipulation
     * @var int
     */
    private static $j;
    /**
     * The constructor for a tokenizer can be used to say that a thesaurus
     * for final query reordering is present. For english we do this if
     * the WORDNET_EXEC variable is set. In which case we use WordNet for
     * our reordering
     */
    public function __construct()
    {
        if (C\WORDNET_EXEC != "") {
            $this->use_thesaurus = true;
        }
    }
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
     * Computes similar words and scores from WordNet output based on word
     * type.
     *
     * @param string $term term to find related thesaurus terms
     * @param string $word_type is the type of word such as "NN" (noun),
     *     "VB" (verb), "AJ" (adjective), or "AV" (adverb)
     *     (all other types will be ignored)
     * @param string $whole_query the original query $term came from
     * @return array a sequence of
     *     (score => array of thesaurus terms) associations. The score
     *     representing one word sense of term
     */
    public static function scoredThesaurusMatches($term, $word_type,
        $whole_query)
    {
        $word_map = ["VB" => "verb", "NN" => "noun", "AJ" => "adj",
            "AV" => "adv"];
        //Gets overview of senses of term[$i] into data
        exec(C\WORDNET_EXEC . " $term -over", $data);
        if (!$data || ! isset($word_map[$word_type])) { return null; }
        $full_name = $word_map[$word_type];
        $lexicon_output = implode("\n", $data);
        $sense_parts = preg_split("/\bThe\s$full_name".'[^\n]*\n\n/',
            $lexicon_output);
        if (!isset($sense_parts[1])) {return null; }
        list($sense, ) = preg_split("/\bOverview\sof\s/", $sense_parts[1]);
        $definitions_for_sense = preg_split("/\d+\.\s/", $sense, -1,
            PREG_SPLIT_NO_EMPTY);
        $num_definitions = count($definitions_for_sense);
        $sentence = [];
        $similar_phrases = [];
        $avg_scores = [];
        for ($i = 0; $i < $num_definitions; $i++) {
            //get sentence fragments examples of using that definition
            preg_match_all('/\"(.*?)\"/', $definitions_for_sense[$i],
                $matches);
            // to separate out the words
            preg_match('/[\w+\s\,\.\']+\s\-+/', $definitions_for_sense[$i],
                $match_word);
            $thesaurus_phrases = preg_split("/\s*\,\s*/",
                strtolower(rtrim(trim($match_word[0]), "-")));
            //remove ori ginal term from thesaurus phrases if present
            $m = 0;
            foreach ($thesaurus_phrases as $thesaurus_phrase) {
                $tphrase = trim($thesaurus_phrase);
                if ($tphrase == trim($term)) {
                    unset($thesaurus_phrases[$m]);
                }
                $m++;
            }
            $thesaurus_phrases = array_filter($thesaurus_phrases);
            if ($thesaurus_phrases == []) {continue;}
            $num_example_sentences = count($matches[1]);
            $score = [];
            for ($j = 0; $j < $num_example_sentences; $j++) {
                $query_parts = explode(' ', strtolower($whole_query));
                $example_sentence_parts = explode(' ',
                    strtolower($matches[1][$j]));
                $score[$j] = PhraseParser::getCosineRank($query_parts,
                    $example_sentence_parts);
                /*  If Cosine similarity is zero then go for
                 * intersection similarity ranking
                 */
                if ($score[$j] == 0) {
                    $score[$j] = PhraseParser::getIntersection($query_parts,
                        $example_sentence_parts);
                }
            }
            /*  We use the rounded average of the above times 100 as a score
                score for a definition. To avoid ties we store in the low
                order digits 99 - the definition it was
             */
            if ($num_example_sentences > 0) {
                $definition_score = 100 * round(
                    100 * (array_sum($score) / $num_example_sentences))
                    + (99 - $i);
            } else {
                $definition_score = 99 - $i;
            }
            $similar_phrases[$definition_score] = $thesaurus_phrases;
        }
        krsort($similar_phrases);
        return $similar_phrases;
    }
    /**
     * Removes the stop words from the page (used for Word Cloud generation)
     *
     * @param string $page the page to remove stop words from.
     * @return string $page with no stop words
     */
    public static function stopwordsRemover($page)
    {
        $stop_words = ['a','able','about','above','abst',
        'accordance','according','based','accordingly','across','act',
        'actually','added','adj','affected','affecting','affects','after',
        'afterwards','again','against','ah','all','almost','alone','along',
        'already','also','although','always','am','among','amongst','an','and',
        'announce','another','any','anybody','anyhow','anymore','anyone',
        'anything','anyway','anyways','anywhere','apparently','approximately',
        'are','aren','arent','arise','around','as','aside','ask','asking','at',
        'auth','available','away','awfully','b','back','be','became','because',
        'become','becomes','becoming','been','before','beforehand','begin',
        'beginning','beginnings','begins','behind','being','believe','below',
        'beside','besides','between','beyond','biol','both','brief','briefly',
        'but','by','c','ca','came','can','cannot','cant','cause','causes',
        'certain','certainly','co','com','come','comes','contain','containing',
        'contains','could','couldnt','d','date','did','didnt',
        'different','do','does','doesnt','doing',
        'done','dont','down','downwards',
        'due','during','e','each','ed','edu','effect','eg','eight','eighty',
        'either','else','elsewhere','end',
        'ending','enough','especially','et',
        'et-al','etc','even','ever','every',
        'everybody','everyone','everything'
        ,'everywhere','ex','except','f','far','few','ff','fifth','first',
        'five','fix','followed','following','follows','for','former',
        'formerly','forth','found','four','from','further','furthermore',
        'g','gave','get','gets','getting','give','given','gives','giving','go',
        'goes','gone','got','gotten','h','had','happens','hardly','has',
        'hasnt','have','havent','having','he','hed','hence','her','here',
        'hereafter','hereby','herein','heres','hereupon','hers','herself',
        'hes','hi','hid','him','himself','his','hither','home','how','howbeit',
        'however', 'http', 'https', 'hundred','i','id','ie','if','ill',
        'im','immediate','immediately',
        'importance','important','in','inc','indeed','index','information',
        'instead','into','invention','inward','is','isnt','it','itd','itll',
        'its','itself','ive','j','just','k','keep','keeps',
        'kept','kg','km','know',
        'known','knows','l','largely','last','lately',
        'later','latter','latterly',
        'least','less','lest','let','lets','like','liked','likely','line',
        'little','ll','look','looking','looks','ltd','m','made','mainly',
        'make','makes','many','may','maybe','me','mean','means','meantime',
        'meanwhile','merely','mg','might','million','miss','ml','more',
        'moreover','most','mostly','mr','mrs','much','mug','must','my',
        'myself','n','na','name','namely','nay','nd','near','nearly',
        'necessarily','necessary','need','needs','neither','never',
        'nevertheless','new','next','nine','ninety','no',
        'nobody','non','none','nonetheless','noone',
        'nor','normally','nos','not',
        'noted','nothing','now','nowhere','o','obtain',
        'obtained','obviously','of',
        'off','often','oh','ok','okay','old','omitted','on','once','one',
        'ones','only','onto','or','ord','other','others',
        'otherwise','ought','our','ours',
        'ourselves','out','outside','over','overall','owing','own','p','page',
        'pages','part','particular','particularly',
        'past','per','perhaps','placed',
        'please','plus','poorly','possible','possibly','potentially','pp',
        'predominantly','present','previously',
        'primarily','probably','promptly',
        'proud','provides','put','q','que','quickly','quite','qv','r','ran',
        'rather','rd','re','readily','really','recent','recently','ref','refs',
        'regarding','regardless','regards','related','relatively','research',
        'respectively','resulted','resulting',
        'results','right','run','s','said',
        'same','saw','say','saying','says','sec',
        'section','see','seeing','seem',
        'seemed','seeming','seems',
        'seen','self','selves','sent','seven','several',
        'shall','she','shed','shell',
        'shes','should','shouldnt','show','showed','shown','showns','shows',
        'significant','significantly','similar','similarly','since',
        'six','slightly',
        'so','some','somebody','somehow','someone','somethan',
        'something','sometime',
        'sometimes','somewhat','somewhere','soon',
        'sorry','specifically','specified',
        'specify','specifying','still','stop','strongly','sub','substantially',
        'successfully','such','sufficiently','suggest','sup','sure','t','take',
        'taken','taking','tell','tends','th','than',
        'thank','thanks','thanx','that',
        'thatll','thats','thatve','the','their',
        'theirs','them','themselves','then',
        'thence','there','thereafter','thereby','thered','therefore','therein',
        'therell','thereof','therere','theres','thereto','thereupon','thereve',
        'these','they','theyd','theyll','theyre',
        'theyve','think','this','those',
        'thou','though','thoughh','thousand','throug',
        'through','throughout','thru',
        'thus','til','tip','to','together','too',
        'took','toward','towards','tried',
        'tries','truly','try','trying','ts','twice','two','u','un','under',
        'unfortunately','unless','unlike','unlikely','until','unto','up',
        'upon','ups','us','use','used','useful','usefully','usefulness',
        'uses','using','usually','v','value','various','ve','very',
        'via','viz','vol','vols','vs',
        'w','want','wants','was','wasnt','way','we',
        'wed','welcome','well','went',
        'were','werent','weve','what','whatever',
        'whatll','whats','when','whence',
        'whenever','where','whereafter','whereas','whereby','wherein','wheres',
        'whereupon','wherever','whether','which','while','whim','whither',
        'who','whod','whoever','whole','wholl','whom','whomever','whos',
        'whose','why','widely','willing','wish','with','within',
        'without','wont','words','world',
        'would','wouldnt','www','x','y','yes','yet','you','youd','youll',
        'your','youre','yours','yourself','yourselves','youve','z','zero'];
        $page = preg_replace('/\b('.implode('|',$stop_words).')\b/', '',
            mb_strtolower($page));
        return $page;
    }
    /**
     * Takes a phrase and tags each term in it with its part of speech.
     * So each term in the original phrase gets mapped to term~part_of_speech
     * This tagger is based on a Brill tagger. It makes uses a lexicon
     * consisting of words from the Brown corpus together with a list of
     * part of speech tags that that word had in the Brown Corpus. These are
     * used to get an initial part of speech (in word was not present than
     * we assume it is a noun). From this a fixed set of rules is used to
     * modify the initial tag if necessary.
     *
     * @param string $phrase text to add parts speech tags to
     * @param bool $with_tokens whether to include the terms and the tags
     *      in the output string or just the part of speech tags
     * @return string $tagged_phrase phrase where each term has ~part_of_speech
     *      appended ($with_tokens == true) or just space separated
     *      part_of_speech (!$with_tokens)
     */
    public static function tagPartsOfSpeechPhrase($phrase, $with_tokens = true)
    {
        $tagged_tokens = self::tagTokenizePartOfSpeech($phrase);
        $tagged_phrase  = self::taggedPartOfSpeechTokensToString(
            $tagged_tokens, $with_tokens);
        return $tagged_phrase;
    }
    /**
     * Split input text into terms and output an array with one element
     * per term, that element consisting of array with the term token
     * and the part of speech tag.
     *
     * @param string $text string to tag and tokenize
     * @return array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term) for one each token in $text
     */
   public static function tagTokenizePartOfSpeech($text)
    {
        static $dictionary = [];
        if (empty($dictionary)) {
            $serial_lex_file = C\LOCALE_DIR .
                "/en_US/resources/serial_lexicon.txt";
            $lex_file = C\LOCALE_DIR . "/en_US/resources/lexicon.txt.gz";
            if (file_exists($serial_lex_file) &&
                filemtime($serial_lex_file) > filemtime($lex_file)) {
                $dictionary = unserialize(file_get_contents($serial_lex_file));
            } else {
                $lines = gzfile($lex_file);
                foreach ($lines as $line) {
                    $tags = explode(' ', $line);
                    $dictionary[strtolower(array_shift($tags))] = $tags;
                }
                $dictionary_string = serialize($dictionary);
                file_put_contents($serial_lex_file, $dictionary_string);
                chmod($serial_lex_file, 0777);
            }
        }
        preg_match_all("/[\w\d]+/", $text, $matches);
        $tokens = $matches[0];
        $nouns = ['NN', 'NNS', 'NNP'];
        $verbs = ['VBD', 'VBP', 'VB'];
        $result = [];
        $previous = ['token' => -1, 'tag' => -1];
        $previous_token = -1;
        // now using our dictionary we tag
        $i = 0;
        $tag_list = [];
        foreach ($tokens as $token) {
            $prev_tag_list = $tag_list;
            $tag_list = [];
            // default to a common noun
            $current = ['token' => $token, 'tag' => 'NN'];
            // remove trailing full stops
            $token = strtolower($token);
            if (!empty($dictionary[$token])) {
                $tag_list = $dictionary[$token];
                $current['tag'] = $tag_list[0];
            }
            // Converts verbs after 'the' to nouns
            if ($previous['tag'] == 'DT' && in_array($current['tag'], $verbs)){
                $current['tag'] = 'NN';
            }
            // Convert noun to number if . appears
            if ($current['tag'][0] == 'N' && strpos($token, '.') !== false) {
                $current['tag'] = 'CD';
            }
            $ends_with = substr($token, -2);
            switch ($ends_with) {
                case 'ed':
                    // Convert noun to past particle if ends with 'ed'
                    if ($current['tag'][0] == 'N') {
                        $current['tag'] = 'VBN';
                    }
                break;
                case 'ly':
                    // Anything that ends 'ly' is an adverb
                    $current['tag'] = 'RB';
                break;
                case 'al':
                    // Common noun to adjective if it ends with al
                    if (in_array($current['tag'], $nouns)) {
                        $current['tag'] = 'JJ';
                    }
                break;
            }
            // Noun to verb if the word before is 'would'
            if ($current['tag'] == 'NN' && $previous_token == 'would') {
                $current['tag'] = 'VB';
            }
            // Convert common noun to gerund
            if (in_array($current['tag'], $nouns) &&
                substr($token, -3) == 'ing') {
                $current['tag'] = 'VBG';
            }
            //nouns followed by adjectives
            if (in_array($previous['tag'], $nouns) &&
                $current['tag'] == 'JJ' && in_array('JJ', $prev_tag_list)) {
                $result[$i - 1]['tag'] = 'JJ';
                $current['tag'] = 'NN';
            }
            /* If we get noun, and the second can be a verb,
             * convert to verb; if noun noun and previous could be an
             * adjective convert to adjective
             */
            if (in_array($previous['tag'], $nouns) &&
                in_array($current['tag'], $nouns) ) {
                if (in_array('VBN', $tag_list)) {
                    $current['tag'] = 'VBN';
                } else if (in_array('VBZ', $tag_list)) {
                    $current['tag'] = 'VBZ';
                } else if (in_array('JJ', $prev_tag_list)) {
                    $result[$i - 1]['tag'] = 'JJ';
                }
            }
            $result[$i] = $current;
            $i++;
            $previous = $current;
            $previous_token = $token;
        }
        return $result;
    }
    /**
     * Takes a phrase query entered by user and return true if it is question
     * and false if not
     *
     * @param $phrase any statement
     * @return bool returns true if statement is question
     */
    public function isQuestion($phrase)
    {
        $regex_starts_with_que =
            "/^(who|what|which|where|when|whose|how)(.*)$/";
        if (preg_match($regex_starts_with_que, trim($phrase))) {
            return true;
        }
        return false;
    }
    /**
     * Computes the stem of an English word
     *
     * For example, jumps, jumping, jumpy, all have jump as a stem
     *
     * @param string $word the string to stem
     * @return string the stem of $words
     */

    public static function stem($word)
    {
        if (in_array($word, self::$no_stem_list)) {
            return $word;
        }

        self::$buffer = $word;
        self::$k = strlen($word) - 1;
        self::$j = self::$k;
        if (self::$k <= 1) { return $word; }
        self::step1ab();
        self::step1c();
        self::step2();
        self::step3();
        self::step4();
        self::step5();
        return substr(self::$buffer, 0, self::$k + 1);
    }
    /**
     * Take in a sentence and try to compress it to a smaller version
     * that "retains the most important information and remains grammatically
     * correct" (Jing 2000).
     *
     * @param string $sentence_to_compress the sentence to compress
     * @return the compressed sentence
     */
    public static function compressSentence($sentence_to_compress)
    {
        $result = $sentence_to_compress;
        $result = self::compressSentenceStep2($result);
        $result = self::compressSentenceStep3($result);
        $result = self::compressSentenceStep4($result);
        $result = self::compressSentenceStep5($result);
        return $result;
    }
    /**
     * Takes a triplets array with subject, predicate, object fields with
     * CONCISE and RAW subfields and rearranges it to have two fields CONCISE
     * and RAW with subject, predicate, object, and QUESTION_ANSWER_LIST
     * subfields
     *
     * @param array $sub_pred_obj_triplets in format described above
     * @return array $processed_triplets in format described above
     */
    public static function rearrangeTripletsByType($sub_pred_obj_triplets)
    {
        $processed_triplet = [];
        $processed_triplets['CONCISE'] =
            self::extractTripletByType($sub_pred_obj_triplets, "CONCISE");
        $processed_triplets['RAW'] =
            self::extractTripletByType($sub_pred_obj_triplets, "RAW");
        return $processed_triplets;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for a determiner if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["curnode" =>
     *      current parse position in $tagged_phrase]
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      "DT" a subarray with a token node for the determiner that was
     *      parsed
     */
    public static function extractDeterminer($tagged_phrase, $tree)
    {
        $cur_node = $tree['cur_node'];
        if (isset($tagged_phrase[$cur_node]['tag']) &&
            trim($tagged_phrase[$cur_node]['tag']) == "DT" ) {
            $tree['DT'] = $tagged_phrase[$cur_node]['token'];
            $tree['cur_node']++;
            return $tree;
        }
        return $tree;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for an adjective if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["cur_node" =>
     *      current parse position in $tagged_phrase]
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      "JJ" a subarray with a token node for the adjective that was
     *      parsed
     */
    public static function extractAdjective($tagged_phrase, $tree)
    {
        $adjective_string = "";
        $cur_node = $tree['cur_node'];
        while (isset($tagged_phrase[$cur_node]['tag']) &&
           in_array(trim($tagged_phrase[$cur_node]['tag']),
           self::$adjective_phrases)) {
           $adjective_string .= " " . $tagged_phrase[$cur_node]['token'];
           $cur_node++;
       }
       if (!empty($adjective_string)) {
           $tree["JJ"] = $adjective_string;
       }
       $tree['cur_node'] = $cur_node;
       return $tree;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for a noun if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["curnode" =>
     *      current parse position in $tagged_phrase]
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      "NN" a subarray with a token node for the noun string that was
     *      parsed
     */
    public static function extractNoun($tagged_phrase, $tree)
    {
        //Combining multiple noun into one
        $noun_string = "";
        $cur_node = $tree['cur_node'];
        while (isset($tagged_phrase[$cur_node]['tag']) &&
            (in_array(trim($tagged_phrase[$cur_node]['tag']),
            self::$noun_phrases))) {
            $noun_string .= " " . $tagged_phrase[$cur_node]['token'];
            $cur_node++;
        }
        if (!empty($noun_string)) {
            $tree["NN"] = $noun_string;
        }
        $tree['cur_node'] = $cur_node;
        return $tree;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for a sequence of
     * prepositional phrases if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["cur_node" =>
     *      current parse position in $tagged_phrase]
     * @param int $index which term in $tagged_phrase to start to try to extract
     *      a preposition from
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      parsed followed by additional possible fields (here i
     *      represents the ith clause found):
     *      "IN_i" with value a preposition subtree
     *      "DT_i" with value a determiner subtree
     *      "JJ_i" with value an adjective subtree
     *      "NN_i"  with value an additional noun subtree
     */
    public static function extractPrepositionalPhrases($tagged_phrase, $tree,
        $index = 1)
    {
       $cur_node = $tree['cur_node'];
        // Checking for preposition.I.e, format: prep [det] [adjective] noun
        if (isset($tagged_phrase[$cur_node]['tag']) &&
            trim($tagged_phrase[$cur_node]['tag']) == "IN") {
            /* can have multiple prep's in a row, for example,
               it is known in over 20 countries*/
            $preposition_string = "";
            while (isset($tagged_phrase[$cur_node]['tag']) &&
                trim($tagged_phrase[$cur_node]['tag']) == "IN") {
                $preposition_string .= " ". $tagged_phrase[$cur_node]['token'];
                $cur_node++;
            }
            if (!empty($preposition_string)) {
                $tree["IN_$index"] = $preposition_string;
            }
            if (isset($tagged_phrase[$cur_node]['tag']) &&
                trim($tagged_phrase[$cur_node]['tag']) == "DT") {
                $tree['DT_$index'] = $tagged_phrase[$cur_node]['token'];
                $cur_node++;
            }
            $adjective_string = "";
            while (isset($tagged_phrase[$cur_node]['tag']) &&
                in_array(trim($tagged_phrase[$cur_node]['tag']),
                self::$adjective_phrases)) {
                $adjective_string .= " " . $tagged_phrase[$cur_node]['token'];
                $cur_node++;
            }
            if (!empty($adjective_string)) {
                $tree["JJ_$index"] = $adjective_string;
            }
            $prep_noun_string = "";
            while (isset($tagged_phrase[$cur_node]['tag']) &&
                in_array(trim($tagged_phrase[$cur_node]['tag']),
                self::$noun_phrases)) {
                $prep_noun_string .= " " . $tagged_phrase[$cur_node]['token'];
                $cur_node++;
            }
            if ($prep_noun_string) {
                $tree["NP_$index"] = $prep_noun_string;
            }
            $tree_next = self::extractPrepositionalPhrases($tagged_phrase,
                ["cur_node" => $cur_node], $index + 1);
        }
        $tree['cur_node'] = $cur_node;
        return $tree;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for a noun phrase if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["curnode" =>
     *      current parse position in $tagged_phrase]
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      "NP" a subarray with possible fields
     *      "DT" with value a determiner subtree
     *      "JJ" with value an adjective subtree
     *      "NN" with value a noun tree
     */
    public static function extractNounPhrase($tagged_phrase, $tree)
    {
        $cur_node = $tree['cur_node'];
        $tree_dt = self::extractDeterminer($tagged_phrase,
            ['cur_node' => $cur_node]);
        $tree_jj = self::extractAdjective($tagged_phrase,
            ['cur_node' => $tree_dt['cur_node']]);
        $tree_nn = self::extractNoun($tagged_phrase,
            ['cur_node' => $tree_jj['cur_node']]);
        $tree_pp = self::extractPrepositionalPhrases($tagged_phrase,
            ['cur_node' => $tree_nn['cur_node']]);
        if ($tree_nn['cur_node'] == $cur_node) {
            $tree['NP'] = "";
        } else {
            $cur_node = $tree_pp['cur_node'];
            unset($tree_dt['cur_node']);
            $tree_new_sub['DT'] = $tree_dt;
            unset($tree_jj['cur_node']);
            $tree_new_sub['JJ'] = $tree_jj;
            unset($tree_nn['cur_node']);
            $tree_new_sub['NN'] = $tree_nn;
            unset($tree_pp['cur_node']);
            $tree_new_sub['PRP'] = $tree_pp;
            $tree_new['cur_node'] = $cur_node;
            $tree_new['NP'] = $tree_new_sub;
            return $tree_new;
        }
        return $tree;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for a verb if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["curnode" =>
     *      current parse position in $tagged_phrase]
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      "VB" a subarray with a token node for the verb string that was
     *      parsed
     */
    public static function extractVerb($tagged_phrase, $tree)
    {
        $cur_node = $tree['cur_node'];
        // skip stuff before verb (intensifiers and adverbs)
        while (isset($tagged_phrase[$cur_node]['tag']) &&
            !in_array(trim($tagged_phrase[$cur_node]['tag']),
            self::$verb_phrases)) {
            $cur_node++;
        }
        $verb_string = "";
        while (isset($tagged_phrase[$cur_node]['tag']) &&
            in_array(trim($tagged_phrase[$cur_node]['tag']),
            self::$verb_phrases)) {
            $verb_string .= " " . $tagged_phrase[$cur_node]['token'];
            $cur_node++;
        }
        if (!empty($verb_string)) {
            $tree["VB"] = $verb_string;
        }
        $tree['cur_node'] = $cur_node;
        return $tree;
    }
    /**
     * Takes a part-of-speech tagged phrase and pre-tree with a
     * parse-from position and builds a parse tree for a verb phrase if possible
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @param array $tree that consists of ["curnode" =>
     *      current parse position in $tagged_phrase]
     * @return array has fields
     *      "cur_node" index of how far we parsed $tagged_phrase
     *      "VP" a subarray with possible fields
     *      "VB" with value a verb subtree
     *      "NP" with value an noun phrase subtree
     */
    public static function extractVerbPhrase($tagged_phrase, $tree)
    {
        $cur_node = $tree['cur_node'];
        $tree_vb = self::extractVerb($tagged_phrase, ['cur_node' => $cur_node]);
        if ($tree_vb['cur_node'] == $cur_node) {
            return $tree;
        }
        $cur_node = $tree_vb['cur_node'];
        $preposition_string = "";
        while (isset($tagged_phrase[$cur_node]['tag']) &&
            trim($tagged_phrase[$cur_node]['tag']) == "IN") {
            $preposition_string .= " ". $tagged_phrase[$cur_node]['token'];
            $cur_node++;
        }
        if (!empty($preposition_string)) {
            $tree_vb["IN"] = $preposition_string;
        }
        $tree_np = self::extractNounPhrase($tagged_phrase,
            ['cur_node' => $cur_node]);
        $tree_new = [];
        $tree_new_sub = [];
        if ($tree_np['cur_node'] !=  $cur_node) {
            $cur_node = $tree_np['cur_node'];
            unset($tree_vb['cur_node']);
            unset($tree_np['cur_node']);
            $tree_new_sub['VB'] = $tree_vb;
            $tree_new_sub['NP'] = $tree_np['NP'];
            $tree_new['cur_node'] = $cur_node;
            $tree_new['VP'] = $tree_new_sub;
            return $tree_new;
        }
        unset($tree_vb['cur_node']);
        $tree_new_sub['VB'] = $tree_vb;
        $tree_new['cur_node'] = $cur_node;
        $tree_new['VP'] = $tree_new_sub;
        return $tree_new;
    }
    /**
     * Given a part-of-speeech tagged phrase array generates a parse tree
     * for the phrase using a recursive descent parser.
     *
     * @param array $tagged_phrase
     *      an array of pairs of the form ("token" => token_for_term,
     *     "tag"=> part_of_speech_tag_for_term)
     * @return array used to represent a tree. The array has up to three fields
     *      $tree["cur_node"] index of how far we parsed our$tagged_phrase
     *      $tree["NP"] contains a subtree for a noun phrase
     *      $tree["VP"] contains a subtree for a verb phrase
     */
    public static function generatePhraseParseTree($tagged_phrase)
    {
        $tree = [];
        //cur_node is the index in tagged_phrase we've parse to so far
        $tree_np = self::extractNounPhrase($tagged_phrase, ["cur_node" => 0]);
        $tree = ["cur_node" => $tree_np['cur_node']];
        $tree_vp = self::extractVerbPhrase($tagged_phrase, $tree);
        if ($tree == $tree_vp) {
            return $tree;
        }
        $tree['cur_node'] = $tree_vp['cur_node'];
        unset($tree_np['cur_node']);
        unset($tree_vp['cur_node']);
        $tree['NP'] = $tree_np['NP'];
        $tree['VP'] = $tree_vp['VP'];
        return $tree;
    }
    /**
     * Takes a parse tree of a phrase and computes subject, predicate, and
     * object arrays. Each of these array consists of two components CONCISE and
     * RAW, CONCISE corresponding to something more similar to the words in the
     * original phrase and RAW to the case where extraneous words have been
     * removed
     *
     * @param are $tree a parse tree for a sentence
     * @return array triplet array
     */
    public static function extractTripletsParseTree($tree)
    {
        $triplets = [];
        $triplets['subject'] = self::extractSubjectParseTree($tree);
        $triplets['predicate'] = self::extractPredicateParseTree($tree);
        $triplets['object'] = self::extractObjectParseTree($tree);
        return $triplets;
    }
    /**
     * Scans a word list for phrases. For phrases found generate
     * a list of question and answer pairs at two levels of granularity:
     * CONCISE (using all terms in orginal phrase) and RAW (removing
     * (adjectives, etc).
     *
     * @param array $word_and_phrase_list of statements
     * @return array with two fields: QUESTION_LIST consisting of triplets
     *      (SUBJECT, PREDICATES, OBJECT) where one of the components has been
     *      replaced with a question marker.
     */
    public static function extractTripletsPhrases($word_and_phrase_list)
    {
        $triplets_list = [];
        $question_list = [];
        $question_answer_list = [];
        $word_and_phrase_list = array_filter($word_and_phrase_list,
            function ($key) {
                return str_word_count($key) >= C\PHRASE_THRESHOLD;
            }, \ARRAY_FILTER_USE_KEY );
        $triplet_types = ['CONCISE', 'RAW'];
        foreach ($word_and_phrase_list as $word_and_phrase => $position_list) {
            $tagged_phrase = self::tagTokenizePartOfSpeech($word_and_phrase);
            $parse_tree = self::generatePhraseParseTree($tagged_phrase);
            $triplets = self::extractTripletsParseTree($parse_tree);
            $extracted_triplets = self::rearrangeTripletsByType($triplets);
            foreach ($triplet_types as $type) {
                if (!empty($extracted_triplets[$type])) {
                    $triplets = $extracted_triplets[$type];
                    $questions = $triplets['QUESTION_LIST'];
                    foreach ($questions as $question) {
                        $question_list[$question] = $position_list;
                    }
                    $question_answer_list = array_merge($question_answer_list,
                        $triplets['QUESTION_ANSWER_LIST']);
                }
            }
        }
        $out_triplets['QUESTION_LIST'] = $question_list;
        $out_triplets['QUESTION_ANSWER_LIST'] = $question_answer_list;
        return $out_triplets;
    }
    /**
     * Takes phrase tree $tree and a part-of-speech $pos returns
     * the deepest $pos only path in tree.
     *
     * @param array $tree phrase to extract type from
     * @param string $pos the part of speech to extract
     * @return string the label of deepest $pos only path in $tree
     */
    public static function extractDeepestSpeechPartPhrase($tree, $pos)
    {
        $extract = "";
        if (!empty($tree[$pos])) {
            $extract = self::extractDeepestSpeechPartPhrase($tree[$pos], $pos);
        }
        if (!$extract && !empty($tree[$pos]) && !empty($tree[$pos][$pos])) {
            $extract = $tree[$pos][$pos];
        }
        return $extract;
    }
    /**
     * Takes a parse tree of a phrase or statement and returns an array
     * with two fields CONCISE and RAW the former having the object of
     * the original phrase (as a string) the latter having the importart
     * parts of the object
     *
     * @param array representation of a parse tree of a phrase
     * @return array with two fields CONCISE and RAW as described above
     */
    public static function extractObjectParseTree($tree)
    {
        $object = [];
        if (!empty($tree['VP'])) {
            $tree_vp = $tree['VP'];
            if (!empty($tree_vp['NP'])) {
                $nb = $tree_vp['NP'];
                $object['CONCISE'] = self::extractDeepestSpeechPartPhrase($nb,
                    "NN");
                $raw_object = "";
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveArrayIterator($nb));
                foreach ($it as $v) {
                    $raw_object .= $v . " ";
                }
                $object['RAW'] = $raw_object;
            } else {
                $object['CONCISE'] = "";
                $object['RAW'] = "";
            }
        } else {
            $object['CONCISE'] = "";
            $object['RAW'] = "";
        }
        return $object;
    }
    /**
     * Takes a parse tree of a phrase or statement and returns an array
     * with two fields CONCISE and RAW the former having the predicate of
     * the original phrase (as a string) the latter having the importart
     * parts of the predicate
     *
     * @param array representation of a parse tree of a phrase
     * @return array with two fields CONCISE and RAW as described above
     */
    public static function extractPredicateParseTree($tree)
    {
        $predicate = [];
        if (!empty($tree['VP'])) {
            $tree_vp = $tree['VP'];
            $predicate['CONCISE'] = self::extractDeepestSpeechPartPhrase(
                $tree_vp, "VB");
            $raw_predicate = "";
            if (!empty($tree_vp['VB'])) {
                $tree_vb = $tree_vp['VB'];
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveArrayIterator($tree_vb));
                foreach ($it as $v) {
                    $raw_predicate .= $v . " ";
                }
                $predicate['RAW'] = $raw_predicate;
            }
        } else {
            $predicate['CONCISE'] = "";
            $predicate['RAW'] = "";
        }
        return $predicate;
    }
    /**
     * Takes a parse tree of a phrase or statement and returns an array
     * with two fields CONCISE and RAW the former having the subject of
     * the original phrase (as a string) the latter having the importart
     * parts of the subject
     *
     * @param array representation of a parse tree of a phrase
     * @return array with two fields CONCISE and RAW as described above
     */
    public static function extractSubjectParseTree($tree)
    {
        $subject = [];
        if (!empty($tree['NP'])) {
            $subject['CONCISE'] = self::extractDeepestSpeechPartPhrase(
                $tree['NP'], "NN");
            $raw_subject = "";
            $it = new \RecursiveIteratorIterator(
                new \RecursiveArrayIterator($tree['NP']));
            foreach ($it as $v) {
                $raw_subject .= $v . " ";
            }
            $subject['RAW'] = $raw_subject;
        } else {
            $subject['CONCISE'] = "";
            $subject['RAW'] = "";
        }
        return $subject;
    }
    /**
     * Takes tagged question string starts with Who
     * and returns question triplet from the question string
     *
     * @param string $tagged_question part-of-speech tagged question
     * @param int $index current index in statement
     * @return array parsed triplet
     */
    public static function parseWhoQuestion($tagged_question, $index)
    {
        $generated_questions = [];
        $question_marker = self::getQuestionMarker();
        $tree = ["cur_node" => $index];
        $tree['NP'] = "WHO";
        $triplets = [];
        $tree_vp = self::extractVerbPhrase($tagged_question, $tree);
        $triplets['predicate'] = self::extractPredicateParseTree(
            $tree_vp);
        $triplets['object'] = self::extractObjectParseTree(
            $tree_vp);
        $triplet_types = ['CONCISE', 'RAW'];
        foreach ($triplet_types as $type) {
            if (!empty($triplets['object'][$type])
                && !empty($triplets['predicate'][$type])) {
                $generated_questions[$type][] =
                    trim($triplets['object'][$type]) .
                    " " . trim($triplets['predicate'][$type]) . " " .
                    $question_marker;
                $generated_questions[$type][] = $question_marker .
                    " " . trim($triplets['predicate'][$type]) .
                    " " . trim($triplets['object'][$type]);
            }
        }
        return $generated_questions;
    }
    /**
     * Takes tagged question string starts with Wh+ except Who
     * and returns question triplet from the question string
     * Unlike the WHO case, here we assume there is an auxliary verb
     * followed by a noun phrase then the rest of the verb phrase. For example,
     * Where is soccer played?
     *
     * @param string $tagged_question part-of-speech tagged question
     * @param $index current index in statement
     * @return array parsed triplet suitable for query look-up
     */
    public static function parseWHPlusQuestion($tagged_question, $index)
    {
        $generated_questions = [];
        $aux_verb = "";
        $question_marker = self::getQuestionMarker();
        while (isset($tagged_question[$index]) &&
            in_array(trim($tagged_question[$index]['tag']),
            self::$verb_phrases)) {
            $token = trim($tagged_question[$index]['token']);
            $aux_verb .= " " . $token;
            $index++;
        }
        $tree = ["cur_node" => $index];
        $tree['NP'] = "WHPlus";
        $triplets = [];
        $tree_np = self::extractNounPhrase($tagged_question, $tree);
        $triplets['subject'] = self::extractSubjectParseTree($tree_np);
        $tree_vp = self::extractVerbPhrase($tagged_question, $tree_np);
        $triplets['predicate'] = self::extractPredicateParseTree($tree_vp);
        if (!empty($aux_verb)) {
            if (!isset($triplets['predicate']['RAW'])) {
                $triplets['predicate']['RAW'] = "";
            }
            $triplets['predicate']['RAW'] = trim($aux_verb) .
                " " . $triplets['predicate']['RAW'];
        }
        $triplet_types = ['CONCISE', 'RAW'];
        foreach ($triplet_types as $type) {
            if (!empty($triplets['subject'][$type])&&
                !empty($triplets['predicate'][$type])) {
                $generated_questions[$type][] =
                    trim($triplets['subject'][$type]) .
                    " " . trim($triplets['predicate'][$type]) .
                    " " . $question_marker;
                $generated_questions[$type][] = $question_marker.
                    " " . trim($triplets['predicate'][$type]) .
                    " " . trim($triplets['subject'][$type]);
            }
        }
        return $generated_questions;
    }
    /**
     * Takes any question started with WH question and returns the
     * triplet from the question
     *
     * @param string $question question to parse
     * @return array question triplet
     */
    public static function questionParser($question)
    {
        $tagged_question = self::tagTokenizePartOfSpeech($question);
        $generated_question_array = [];
        if (isset($tagged_question[0])) {
            if (in_array(trim($tagged_question[0]['tag']),
                ["WRB", "WP"])) {
                $token = strtoupper(trim($tagged_question[0]['token']));
                if ($token == "WHO") {
                    $generated_questions = self::parseWhoQuestion(
                        $tagged_question, 1);
                } else if (in_array($token, ["WHERE", "WHEN", "WHAT"])) {
                    $generated_questions = self::parseWHPlusQuestion(
                        $tagged_question, 1);
                }
            }
        }
        return $generated_questions;
    }
    /**
     * Takes a triplets array with subject, predicate, object fields with
     * CONCISE, RAW subfields and produces a triplits with $type subfield (where
     * $type is one of CONCISE and RAW) and with subject, predicate, object,
     * and QUESTION_ANSWER_LIST subfields
     *
     * @param array $sub_pred_obj_triplets  in format described above
     * @param string $type either CONCISE or RAW
     * @return array $triplets in format described above
     */
    public static function extractTripletByType($sub_pred_obj_triplets, $type)
    {
        $triplets = [];
        if (!empty($sub_pred_obj_triplets['subject'][$type])
            && !empty($sub_pred_obj_triplets['predicate'][$type])
            && !empty($sub_pred_obj_triplets['object'][$type])) {
            $question_answer_triplets = [];
            $question_marker = self::$question_marker;
            $sentence = [ trim($sub_pred_obj_triplets['subject'][$type]),
                trim($sub_pred_obj_triplets['predicate'][$type]),
                trim($sub_pred_obj_triplets['object'][$type])];
            $question_triplets = [];
            for ($j = 0; $j < 2; $j++) {
                if ($j == 1 && in_array($sentence[1], ['is', 'was'])) {
                    $tmp = $sentence[2];
                    $sentence[2] = $sentence[0];
                    $sentence[0] = $tmp;
                } else if ($j == 1) {
                    break;
                }
                for ($i = 0; $i < 3; $i++) {
                    $q_sentence = $sentence;
                    $q_sentence[$i] = $question_marker;
                    $q_sentence_string = implode(" ", $q_sentence);
                    $q_sentence_string = self::stemPhrase($q_sentence_string);
                    $question_triplets[] = $q_sentence_string;
                    $question_answer_triplets[$q_sentence_string] =
                        preg_replace('/\s+/u', ' ',$sentence[$i]);
                }
            }
            $triplets['QUESTION_LIST'] = $question_triplets;
            $triplets['QUESTION_ANSWER_LIST'] = $question_answer_triplets;
        }
        return $triplets;
    }
    /**
     * Given an English phrase produces a phrase where each of the terms has
     * been stemmed
     *
     * @param string $phrase phrase to stem
     * @return string in which each term has been stemmed according to the
     *      English stemmer
     */
    private static function stemPhrase($phrase)
    {
        $terms = mb_split("[[:space:]]", $phrase);
        $stemmed_phrase = "";
        $space = "";
        foreach ($terms as $term) {
            if (trim($term) == "") {
                    continue;
            }
            $stemmed_phrase .= $space . self::stem($term);
            $space = " ";
        }
        return $stemmed_phrase;
    }
    // private methods for stemming
    /**
     * Checks to see if the ith character in the buffer is a consonant
     *
     * @param int $i the character to check
     * @return if the ith character is a constant
     */
    private static function cons($i)
    {
        switch (self::$buffer[$i]) {
            case 'a':
                // no break
            case 'e':
            case 'i':
            case 'o':
            case 'u':
                return false;
            case 'y':
                return ($i== 0 ) ? true : !self::cons($i - 1);
            default:
                return true;
        }
    }
    /**
     * m() measures the number of consonant sequences between 0 and j. if c is
     * a consonant sequence and v a vowel sequence, and [.] indicates arbitrary
     * presence,
     * <pre>
     *   [c][v]       gives 0
     *   [c]vc[v]     gives 1
     *   [c]vcvc[v]   gives 2
     *   [c]vcvcvc[v] gives 3
     *   ....
     * </pre>
     */
    private static function m()
    {
        $n = 0;
        $i = 0;
        while(true) {
            if ($i > self::$j) return $n;
            if (!self::cons($i)) break;
            $i++;
        }
        $i++;
        while(true) {
            while(true) {
                if ($i > self::$j) return $n;
                if (self::cons($i)) break;
                $i++;
            }
            $i++;
            $n++;

            while(true)
            {
                if ($i > self::$j) return $n;
                if (!self::cons($i)) break;
                $i++;
            }
            $i++;
        }
    }
    /**
     * Checks if 0,...$j contains a vowel
     *
     * @return bool whether it does not
     */
    private static function vowelinstem()
    {
        for ($i = 0; $i <= self::$j; $i++) {
            if (!self::cons($i)) return true;
        }
        return false;
    }
    /**
     * Checks if $j,($j-1) contain a double consonant.
     *
     * @param int $j position to check in buffer for double consonant
     * @return bool if it does or not
     */
    private static function doublec($j)
    {
        if ($j < 1) { return false; }
        if (self::$buffer[$j] != self::$buffer[$j - 1]) { return false; }
        return self::cons($j);
    }
    /**
     * Checks whether the letters at the indices $i-2, $i-1, $i in the buffer
     * have the form consonant - vowel - consonant and also if the second c is
     * not w,x or y. this is used when trying to restore an e at the end of a
     * short word. e.g.
     *<pre>
     *   cav(e), lov(e), hop(e), crim(e), but
     *   snow, box, tray.
     *</pre>
     * @param int $i position to check in buffer for consonant-vowel-consonant
     * @return bool whether the letters at indices have the given form
     */
    private static function cvc($i)
    {
        if ($i < 2 || !self::cons($i) || self::cons($i - 1) ||
            !self::cons($i - 2)) return false;
        $ch = self::$buffer[$i];
        if ($ch == 'w' || $ch == 'x' || $ch == 'y') return false;
        return true;
    }
    /**
     * Checks if the buffer currently ends with the string $s
     *
     * @param string $s string to use for check
     * @return bool whether buffer currently ends with $s
     */
    private static function ends($s)
    {
        $len = strlen($s);
        $loc = self::$k - $len + 1;
        if ($loc < 0 ||
            substr_compare(self::$buffer, $s, $loc, $len) != 0) {
            return false;
        }
        self::$j = self::$k - $len;
        return true;
    }
    /**
     * setto($s) sets (j+1),...k to the characters in the string $s,
     * readjusting k.
     *
     * @param string $s string to modify the end of buffer with
     */
    private static function setto($s)
    {
        $len = strlen($s);
        $loc = self::$j + 1;
        self::$buffer = substr_replace(self::$buffer, $s, $loc, $len);
        self::$k = self::$j + $len;
    }
    /**
     * Sets the ending in the buffer to $s if the number of consonant sequences
     * between $k and $j is positive.
     *
     * @param string $s what to change the suffix to
     */
    private static function r($s)
    {
        if (self::m() > 0) self::setto($s);
    }

    /** step1ab() gets rid of plurals and -ed or -ing. e.g.
     * <pre>
     *    caresses  ->  caress
     *    ponies    ->  poni
     *    ties      ->  ti
     *    caress    ->  caress
     *    cats      ->  cat
     *
     *    feed      ->  feed
     *    agreed    ->  agree
     *    disabled  ->  disable
     *
     *    matting   ->  mat
     *    mating    ->  mate
     *    meeting   ->  meet
     *    milling   ->  mill
     *    messing   ->  mess
     *
     *    meetings  ->  meet
     * </pre>
     */
    private static function step1ab()
    {
        if (self::$buffer[self::$k] == 's') {
            if (self::ends("sses")) {
                self::$k -= 2;
            } else if (self::ends("ies")) {
                self::setto("i");
            } else if (self::$buffer[self::$k - 1] != 's') {
                self::$k--;
            }
        }
        if (self::ends("eed")) {
            if (self::m() > 0) self::$k--;
        } else if ((self::ends("ed") || self::ends("ing")) &&
            self::vowelinstem()) {
            self::$k = self::$j;
            if (self::ends("at")) {
                self::setto("ate");
            } else if (self::ends("bl")) {
                self::setto("ble");
            } else if (self::ends("iz")) {
                self::setto("ize");
            } else if (self::doublec(self::$k)) {
                self::$k--;
                $ch = self::$buffer[self::$k];
                if ($ch == 'l' || $ch == 's' || $ch == 'z') self::$k++;
            } else if (self::m() == 1 && self::cvc(self::$k)) {
                self::setto("e");
            }
       }
    }
    /**
     * step1c() turns terminal y to i when there is another vowel in the stem.
     */
    private static function step1c()
    {
        if (self::ends("y") && self::vowelinstem()) {
            self::$buffer[self::$k] = 'i';
        }
    }
    /**
     * step2() maps double suffices to single ones. so -ization ( = -ize plus
     * -ation) maps to -ize etc.Note that the string before the suffix must
     * give m() > 0.
     */
    private static function step2()
    {
        if (self::$k < 1) return;
        switch (self::$buffer[self::$k - 1]) {
            case 'a':
                if (self::ends("ational")) { self::r("ate"); break; }
                if (self::ends("tional")) { self::r("tion"); break; }
                break;
            case 'c':
                if (self::ends("enci")) { self::r("ence"); break; }
                if (self::ends("anci")) { self::r("ance"); break; }
                break;
            case 'e':
                if (self::ends("izer")) { self::r("ize"); break; }
                break;
            case 'l':
                if (self::ends("abli")) { self::r("able"); break; }
                if (self::ends("alli")) { self::r("al"); break; }
                if (self::ends("entli")) { self::r("ent"); break; }
                if (self::ends("eli")) { self::r("e"); break; }
                if (self::ends("ousli")) { self::r("ous"); break; }
                break;
            case 'o':
                if (self::ends("ization")) { self::r("ize"); break; }
                if (self::ends("ation")) { self::r("ate"); break; }
                if (self::ends("ator")) { self::r("ate"); break; }
                break;
            case 's':
                if (self::ends("alism")) { self::r("al"); break; }
                if (self::ends("iveness")) { self::r("ive"); break; }
                if (self::ends("fulness")) { self::r("ful"); break; }
                if (self::ends("ousness")) { self::r("ous"); break; }
                break;
            case 't':
                if (self::ends("aliti")) { self::r("al"); break; }
                if (self::ends("iviti")) { self::r("ive"); break; }
                if (self::ends("biliti")) { self::r("ble"); break; }
                break;
        }
    }
    /**
     * step3() deals with -ic-, -full, -ness etc. similar strategy to step2.
     */
    private static function step3()
    {
        switch (self::$buffer[self::$k]) {
            case 'e':
                if (self::ends("icate")) { self::r("ic"); break; }
                if (self::ends("ative")) { self::r(""); break; }
                if (self::ends("alize")) { self::r("al"); break; }
                break;
            case 'i':
                if (self::ends("iciti")) { self::r("ic"); break; }
                break;
            case 'l':
                if (self::ends("ical")) { self::r("ic"); break; }
                if (self::ends("ful")) { self::r(""); break; }
                break;
            case 's':
                if (self::ends("ness")) { self::r(""); break; }
                break;
        }
    }
    /**
     * step4() takes off -ant, -ence etc., in context <c>vcvc<v>.
     */
    private static function step4()
    {
        if (self::$k < 1) { return; }
        switch (self::$buffer[self::$k - 1]) {
            case 'a':
                if (self::ends("al")) { break; }
                return;
            case 'c':
                if (self::ends("ance")) { break; }
                if (self::ends("ence")) { break; }
                return;
            case 'e':
                if (self::ends("er")) break;
                return;
            case 'i':
                if (self::ends("ic")) break;
                return;
            case 'l':
                if (self::ends("able")) break;
                if (self::ends("ible")) break;
                return;
            case 'n':
                if (self::ends("ant")) break;
                if (self::ends("ement")) break;
                if (self::ends("ment")) break;
                if (self::ends("ent")) break;
                return;
            case 'o':
                if (self::ends("ion") && self::$j >= 0 &&
                    (self::$buffer[self::$j] == 's' ||
                    self::$buffer[self::$j] == 't')) break;
                if (self::ends("ou")) break;
                return;
            /* takes care of -ous */
            case 's':
                if (self::ends("ism")) break;
                return;
            case 't':
                if (self::ends("ate")) break;
                if (self::ends("iti")) break;
                    return;
            case 'u':
                if (self::ends("ous")) break;
                return;
            case 'v':
                if (self::ends("ive")) break;
                return;
            case 'z':
                if (self::ends("ize")) break;
                return;
            default:
                return;
        }
        if (self::m() > 1) self::$k = self::$j;
    }
    /** step5() removes a final -e if m() > 1, and changes -ll to -l if
     * m() > 1.
     */
    private static function step5()
    {
        self::$j = self::$k;

        if (self::$buffer[self::$k] == 'e') {
            $a = self::m();
            if ($a > 1 || $a == 1 && !self::cvc(self::$k - 1)) self::$k--;
        }
        if (self::$buffer[self::$k] == 'l' &&
            self::doublec(self::$k) && self::m() > 1) self::$k--;
    }
    //private methods for part of speech tagging
    /**
     * Takes an array of pairs (token, tag) that came from phrase
     * and builds a new phrase where terms look like token~tag.
     *
     * @param array $tagged_tokens array pairs as might come from tagTokenize
     * @param bool $with_tokens whether to include the terms and the tags
     *      in the output string or just the part of speech tags
     * @return $tagged_phrase a phrase with terms in the format token~tag
     *      ($with_token == true) or space separated tags (!$with_token).
     */
    private static function taggedPartOfSpeechTokensToString($tagged_tokens,
        $with_tokens = true)
    {
        $tagged_phrase = "";
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
    /**
     * The function returns the question marker for the locale
     *
     * @return the question marker
     */
    public static function getQuestionMarker()
    {
        return self::$question_marker;
    }
    /**
     * From Back to Basics: CLASSY 2006 page 3:
     * 2. We remove many adverbs and all conjunctions, including phrases such
     * as "As a matter of fact," and "At this point," that occur at the start
     * of a sentence.
     *
     * @param string $sentence_to_compress the sentence to compress
     * @return the compressed sentence
     */
    public static function compressSentenceStep2($sentence_to_compress)
    {
        $result = $sentence_to_compress;
        $result = preg_replace("/^At this point,?/i", "", $result);
        $result = preg_replace("/^As a matter of fact,?/i", "", $result);
        //adverbs
        $result = preg_replace("/^[a-zA-Z]*ly\s?/i", "", $result);
        //conjunctions
        $result = preg_replace("/(^and,?)|(^but,?)|(^for,?)|(^nor,?)|(^or,?)" .
            "|(^so,?)|(^yet,?)/i", "", $result);
        return $result;
    }
    /**
     * From Back to Basics: CLASSY 2006 page 3:
     * 3. We remove a small selections of words that occur in the middle of a
     * sentence, such as ", however," and ", also," (not always requiring the
     * commas).
     *
     * @param string $sentence_to_compress the sentence to compress
     * @return the compressed sentence
     */
    public static function compressSentenceStep3($sentence_to_compress)
    {
        $result = $sentence_to_compress;
        $result = preg_replace("/,?\s?however,?/i", "", $result);
        $result = preg_replace("/,?\s?also,?/i", "", $result);
        return $result;
    }
    /**
     * From Back to Basics: CLASSY 2006 page 3:
     * 4. For DUC 2006, we added the removal of ages such as ", 51," or
     * ", aged 24,".
     *
     * @param string $sentence_to_compress the sentence to compress
     * @return the compressed sentence
     */
    public static function compressSentenceStep4($sentence_to_compress)
    {
        $result = $sentence_to_compress;
        $result = preg_replace("/,\s?\d{1,3},/i", "", $result);
        $result = preg_replace("/,\s?aged\s?\d{1,3},/i", "", $result);
        return $result;
    }
    /**
     * From Back to Basics: CLASSY 2006 page 3:
     * 6. We remove relative clause attributives (clauses beginning with
     * "who(m)", "which", "when", and "where") wherever possible.
     *
     * @param string $sentence_to_compress the sentence to compress
     * @return the compressed sentence
     */
    public static function compressSentenceStep5($sentence_to_compress)
    {
        $result = $sentence_to_compress;
        $result = preg_replace("/(,\s?whom?[^,]*,)|(,\s?which[^,]*,)|" .
            "(,\s?when[^,]*,)|(,\s?where[^,]*,)/i", "", $result);
        return $result;
    }
}
