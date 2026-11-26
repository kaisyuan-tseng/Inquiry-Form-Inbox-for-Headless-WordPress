<?php
/**
 * IFI_Locale — 把浏览器语言的 BCP 47 标签显示成「当前界面语言对它的称呼（地区）」。
 *
 * 询盘里存的是 `navigator.language` 原值（`en-US`、`zh-CN`、`ar-EG`…），直接列在看板上
 * 是给开发看的，不是给站长看的。这里翻成「英语（美国）」这样的读法。
 *
 * 为什么自带一份名称表而不是用 intl：`Locale::getDisplayName()` 依赖 intl 扩展，
 * 而共享主机上它经常没装（本项目开发机就没有）。WordPress 自带的语言包清单只给
 * 各语言的**母语**名称（en_US → "English (United States)"），拿不到「中文界面下的英语」。
 * 所以名称按插件支持的三种界面语言各存一份，查不到就原样返回标签——不会比现在更差。
 *
 * 表里只有语言名与地区名，拼接用的 `%1$s (%2$s)` 走 gettext，中文自动是全角括号。
 *
 * @package Inquiry_Form_Inbox
 */

defined( 'ABSPATH' ) || exit;

final class IFI_Locale {

	/**
	 * 语言名：[英文, 简体中文, 繁体中文]。键是 ISO 639-1 两字母码。
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	private const LANGUAGES = array(
		'en'  => array( 'English', '英语', '英文' ),
		'zh'  => array( 'Chinese (Simplified)', '简体中文', '簡體中文' ),
		'es'  => array( 'Spanish', '西班牙语', '西班牙文' ),
		'hi'  => array( 'Hindi', '印地语', '印地文' ),
		'ar'  => array( 'Arabic', '阿拉伯语', '阿拉伯文' ),
		'pt'  => array( 'Portuguese', '葡萄牙语', '葡萄牙文' ),
		'ru'  => array( 'Russian', '俄语', '俄文' ),
		'ja'  => array( 'Japanese', '日语', '日文' ),
		'de'  => array( 'German', '德语', '德文' ),
		'fr'  => array( 'French', '法语', '法文' ),
		'ko'  => array( 'Korean', '韩语', '韓文' ),
		'it'  => array( 'Italian', '意大利语', '義大利文' ),
		'tr'  => array( 'Turkish', '土耳其语', '土耳其文' ),
		'vi'  => array( 'Vietnamese', '越南语', '越南文' ),
		'th'  => array( 'Thai', '泰语', '泰文' ),
		'id'  => array( 'Indonesian', '印尼语', '印尼文' ),
		'pl'  => array( 'Polish', '波兰语', '波蘭文' ),
		'nl'  => array( 'Dutch', '荷兰语', '荷蘭文' ),
		'uk'  => array( 'Ukrainian', '乌克兰语', '烏克蘭文' ),
		'ro'  => array( 'Romanian', '罗马尼亚语', '羅馬尼亞文' ),
		'el'  => array( 'Greek', '希腊语', '希臘文' ),
		'cs'  => array( 'Czech', '捷克语', '捷克文' ),
		'sv'  => array( 'Swedish', '瑞典语', '瑞典文' ),
		'hu'  => array( 'Hungarian', '匈牙利语', '匈牙利文' ),
		'da'  => array( 'Danish', '丹麦语', '丹麥文' ),
		'fi'  => array( 'Finnish', '芬兰语', '芬蘭文' ),
		'no'  => array( 'Norwegian', '挪威语', '挪威文' ),
		'he'  => array( 'Hebrew', '希伯来语', '希伯來文' ),
		'ms'  => array( 'Malay', '马来语', '馬來文' ),
		'fil' => array( 'Filipino', '菲律宾语', '菲律賓文' ),
		'bn'  => array( 'Bengali', '孟加拉语', '孟加拉文' ),
		'ur'  => array( 'Urdu', '乌尔都语', '烏爾都文' ),
		'fa'  => array( 'Persian', '波斯语', '波斯文' ),
		'sw'  => array( 'Swahili', '斯瓦希里语', '史瓦希里文' ),
		'bg'  => array( 'Bulgarian', '保加利亚语', '保加利亞文' ),
		'sk'  => array( 'Slovak', '斯洛伐克语', '斯洛伐克文' ),
		'hr'  => array( 'Croatian', '克罗地亚语', '克羅埃西亞文' ),
		'sr'  => array( 'Serbian', '塞尔维亚语', '塞爾維亞文' ),
		'sl'  => array( 'Slovenian', '斯洛文尼亚语', '斯洛維尼亞文' ),
		'lt'  => array( 'Lithuanian', '立陶宛语', '立陶宛文' ),
		'lv'  => array( 'Latvian', '拉脱维亚语', '拉脫維亞文' ),
		'et'  => array( 'Estonian', '爱沙尼亚语', '愛沙尼亞文' ),
		'ca'  => array( 'Catalan', '加泰罗尼亚语', '加泰蘭文' ),
		'eu'  => array( 'Basque', '巴斯克语', '巴斯克文' ),
		'gl'  => array( 'Galician', '加利西亚语', '加利西亞文' ),
		'is'  => array( 'Icelandic', '冰岛语', '冰島文' ),
		'ga'  => array( 'Irish', '爱尔兰语', '愛爾蘭文' ),
		'cy'  => array( 'Welsh', '威尔士语', '威爾斯文' ),
		'af'  => array( 'Afrikaans', '南非荷兰语', '南非荷蘭文' ),
		'am'  => array( 'Amharic', '阿姆哈拉语', '阿姆哈拉文' ),
		'ne'  => array( 'Nepali', '尼泊尔语', '尼泊爾文' ),
		'si'  => array( 'Sinhala', '僧伽罗语', '僧伽羅文' ),
		'ta'  => array( 'Tamil', '泰米尔语', '坦米爾文' ),
		'te'  => array( 'Telugu', '泰卢固语', '泰盧固文' ),
		'ml'  => array( 'Malayalam', '马拉雅拉姆语', '馬拉雅拉姆文' ),
		'kn'  => array( 'Kannada', '卡纳达语', '卡納達文' ),
		'mr'  => array( 'Marathi', '马拉地语', '馬拉地文' ),
		'gu'  => array( 'Gujarati', '古吉拉特语', '古吉拉特文' ),
		'pa'  => array( 'Punjabi', '旁遮普语', '旁遮普文' ),
		'az'  => array( 'Azerbaijani', '阿塞拜疆语', '亞塞拜然文' ),
		'kk'  => array( 'Kazakh', '哈萨克语', '哈薩克文' ),
		'uz'  => array( 'Uzbek', '乌兹别克语', '烏茲別克文' ),
		'hy'  => array( 'Armenian', '亚美尼亚语', '亞美尼亞文' ),
		'ka'  => array( 'Georgian', '格鲁吉亚语', '喬治亞文' ),
		'mn'  => array( 'Mongolian', '蒙古语', '蒙古文' ),
		'km'  => array( 'Khmer', '高棉语', '高棉文' ),
		'lo'  => array( 'Lao', '老挝语', '寮文' ),
		'my'  => array( 'Burmese', '缅甸语', '緬甸文' ),
	);

	/**
	 * 地区名：[英文, 简体中文, 繁体中文]。键是 ISO 3166-1 两字母码。
	 *
	 * 两岸四地的写法按一个中国口径固定：中国台湾 / 中国香港 / 中国澳门。
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	private const REGIONS = array(
		'US'  => array( 'United States', '美国', '美國' ),
		'GB'  => array( 'United Kingdom', '英国', '英國' ),
		'CA'  => array( 'Canada', '加拿大', '加拿大' ),
		'AU'  => array( 'Australia', '澳大利亚', '澳洲' ),
		'NZ'  => array( 'New Zealand', '新西兰', '紐西蘭' ),
		'IE'  => array( 'Ireland', '爱尔兰', '愛爾蘭' ),
		'CN'  => array( 'China', '中国', '中國' ),
		'TW'  => array( 'Taiwan, China', '中国台湾', '中國台灣' ),
		'HK'  => array( 'Hong Kong, China', '中国香港', '中國香港' ),
		'MO'  => array( 'Macao, China', '中国澳门', '中國澳門' ),
		'JP'  => array( 'Japan', '日本', '日本' ),
		'KR'  => array( 'South Korea', '韩国', '韓國' ),
		'SG'  => array( 'Singapore', '新加坡', '新加坡' ),
		'MY'  => array( 'Malaysia', '马来西亚', '馬來西亞' ),
		'TH'  => array( 'Thailand', '泰国', '泰國' ),
		'VN'  => array( 'Vietnam', '越南', '越南' ),
		'ID'  => array( 'Indonesia', '印度尼西亚', '印尼' ),
		'PH'  => array( 'Philippines', '菲律宾', '菲律賓' ),
		'IN'  => array( 'India', '印度', '印度' ),
		'PK'  => array( 'Pakistan', '巴基斯坦', '巴基斯坦' ),
		'BD'  => array( 'Bangladesh', '孟加拉国', '孟加拉' ),
		'LK'  => array( 'Sri Lanka', '斯里兰卡', '斯里蘭卡' ),
		'NP'  => array( 'Nepal', '尼泊尔', '尼泊爾' ),
		'AE'  => array( 'United Arab Emirates', '阿联酋', '阿聯酋' ),
		'SA'  => array( 'Saudi Arabia', '沙特阿拉伯', '沙烏地阿拉伯' ),
		'IL'  => array( 'Israel', '以色列', '以色列' ),
		'TR'  => array( 'Turkey', '土耳其', '土耳其' ),
		'EG'  => array( 'Egypt', '埃及', '埃及' ),
		'ZA'  => array( 'South Africa', '南非', '南非' ),
		'NG'  => array( 'Nigeria', '尼日利亚', '奈及利亞' ),
		'KE'  => array( 'Kenya', '肯尼亚', '肯亞' ),
		'MA'  => array( 'Morocco', '摩洛哥', '摩洛哥' ),
		'BR'  => array( 'Brazil', '巴西', '巴西' ),
		'MX'  => array( 'Mexico', '墨西哥', '墨西哥' ),
		'AR'  => array( 'Argentina', '阿根廷', '阿根廷' ),
		'CL'  => array( 'Chile', '智利', '智利' ),
		'CO'  => array( 'Colombia', '哥伦比亚', '哥倫比亞' ),
		'PE'  => array( 'Peru', '秘鲁', '秘魯' ),
		'VE'  => array( 'Venezuela', '委内瑞拉', '委內瑞拉' ),
		'EC'  => array( 'Ecuador', '厄瓜多尔', '厄瓜多' ),
		'UY'  => array( 'Uruguay', '乌拉圭', '烏拉圭' ),
		'PY'  => array( 'Paraguay', '巴拉圭', '巴拉圭' ),
		'BO'  => array( 'Bolivia', '玻利维亚', '玻利維亞' ),
		'CR'  => array( 'Costa Rica', '哥斯达黎加', '哥斯大黎加' ),
		'PA'  => array( 'Panama', '巴拿马', '巴拿馬' ),
		'DO'  => array( 'Dominican Republic', '多米尼加', '多明尼加' ),
		'GT'  => array( 'Guatemala', '危地马拉', '瓜地馬拉' ),
		'CU'  => array( 'Cuba', '古巴', '古巴' ),
		'PR'  => array( 'Puerto Rico', '波多黎各', '波多黎各' ),
		'DE'  => array( 'Germany', '德国', '德國' ),
		'FR'  => array( 'France', '法国', '法國' ),
		'ES'  => array( 'Spain', '西班牙', '西班牙' ),
		'IT'  => array( 'Italy', '意大利', '義大利' ),
		'PT'  => array( 'Portugal', '葡萄牙', '葡萄牙' ),
		'NL'  => array( 'Netherlands', '荷兰', '荷蘭' ),
		'BE'  => array( 'Belgium', '比利时', '比利時' ),
		'CH'  => array( 'Switzerland', '瑞士', '瑞士' ),
		'AT'  => array( 'Austria', '奥地利', '奧地利' ),
		'SE'  => array( 'Sweden', '瑞典', '瑞典' ),
		'NO'  => array( 'Norway', '挪威', '挪威' ),
		'DK'  => array( 'Denmark', '丹麦', '丹麥' ),
		'FI'  => array( 'Finland', '芬兰', '芬蘭' ),
		'IS'  => array( 'Iceland', '冰岛', '冰島' ),
		'PL'  => array( 'Poland', '波兰', '波蘭' ),
		'CZ'  => array( 'Czechia', '捷克', '捷克' ),
		'SK'  => array( 'Slovakia', '斯洛伐克', '斯洛伐克' ),
		'HU'  => array( 'Hungary', '匈牙利', '匈牙利' ),
		'RO'  => array( 'Romania', '罗马尼亚', '羅馬尼亞' ),
		'BG'  => array( 'Bulgaria', '保加利亚', '保加利亞' ),
		'GR'  => array( 'Greece', '希腊', '希臘' ),
		'HR'  => array( 'Croatia', '克罗地亚', '克羅埃西亞' ),
		'SI'  => array( 'Slovenia', '斯洛文尼亚', '斯洛維尼亞' ),
		'RS'  => array( 'Serbia', '塞尔维亚', '塞爾維亞' ),
		'BA'  => array( 'Bosnia and Herzegovina', '波黑', '波士尼亞與赫塞哥維納' ),
		'ME'  => array( 'Montenegro', '黑山', '蒙特內哥羅' ),
		'MK'  => array( 'North Macedonia', '北马其顿', '北馬其頓' ),
		'AL'  => array( 'Albania', '阿尔巴尼亚', '阿爾巴尼亞' ),
		'EE'  => array( 'Estonia', '爱沙尼亚', '愛沙尼亞' ),
		'LV'  => array( 'Latvia', '拉脱维亚', '拉脫維亞' ),
		'LT'  => array( 'Lithuania', '立陶宛', '立陶宛' ),
		'UA'  => array( 'Ukraine', '乌克兰', '烏克蘭' ),
		'BY'  => array( 'Belarus', '白俄罗斯', '白俄羅斯' ),
		'MD'  => array( 'Moldova', '摩尔多瓦', '摩爾多瓦' ),
		'GE'  => array( 'Georgia', '格鲁吉亚', '喬治亞' ),
		'AM'  => array( 'Armenia', '亚美尼亚', '亞美尼亞' ),
		'AZ'  => array( 'Azerbaijan', '阿塞拜疆', '亞塞拜然' ),
		'KZ'  => array( 'Kazakhstan', '哈萨克斯坦', '哈薩克' ),
		'UZ'  => array( 'Uzbekistan', '乌兹别克斯坦', '烏茲別克' ),
		'RU'  => array( 'Russia', '俄罗斯', '俄羅斯' ),
		'419' => array( 'Latin America', '拉丁美洲', '拉丁美洲' ),
	);

	/**
	 * 标签 → 显示名。
	 *
	 * @param string $tag            浏览器语言标签，如 `en-US`、`zh-Hant-TW`.
	 * @param string $display_locale 界面语言（`zh_CN` / `zh_TW` / 其它）.
	 * @return string 认不出来时原样返回标签，绝不返回空串（调用方还要显示点什么）.
	 */
	public static function label( string $tag, string $display_locale = '' ): string {
		$tag = trim( $tag );
		if ( '' === $tag ) {
			return '';
		}

		$index    = self::column( $display_locale );
		$parts    = preg_split( '/[-_]/', $tag ) ?: array();
		$language = strtolower( (string) array_shift( $parts ) );
		$script   = '';
		$region   = '';

		foreach ( $parts as $part ) {
			$part = (string) $part;
			if ( 4 === strlen( $part ) && ctype_alpha( $part ) ) {
				$script = ucfirst( strtolower( $part ) );
			} elseif ( ( 2 === strlen( $part ) && ctype_alpha( $part ) ) || ( 3 === strlen( $part ) && ctype_digit( $part ) ) ) {
				$region = strtoupper( $part );
			}
		}

		$name = self::language_name( $language, $script, $region, $index );
		if ( '' === $name ) {
			return $tag;
		}

		$place = self::region_name( $region, $index );
		if ( '' === $place ) {
			return $name;
		}

		// 与「来源」列的「页面标题（表单类型）」共用同一个 msgid：中文全角括号、英文半角。
		/* translators: 1: language name, 2: region name shown in parentheses. */
		return sprintf( __( '%1$s (%2$s)', 'inquiry-form-inbox' ), $name, $place );
	}

	/**
	 * 语言名。`zh` 按书写系统分流：繁体（Hant / TW / HK / MO）另一种写法。
	 *
	 * @param string $language 两/三字母语言码.
	 * @param string $script   书写系统，如 `Hant`.
	 * @param string $region   地区码.
	 * @param int    $index    0 英文 / 1 简体 / 2 繁体.
	 * @return string
	 */
	private static function language_name( string $language, string $script, string $region, int $index ): string {
		if ( '' === $language ) {
			return '';
		}

		if ( 'zh' === $language ) {
			$traditional = 'Hant' === $script || in_array( $region, array( 'TW', 'HK', 'MO' ), true );
			if ( $traditional ) {
				return 1 === $index ? '繁体中文' : ( 2 === $index ? '繁體中文' : 'Chinese (Traditional)' );
			}
			return self::pick( self::LANGUAGES['zh'], $index );
		}

		return isset( self::LANGUAGES[ $language ] ) ? self::pick( self::LANGUAGES[ $language ], $index ) : '';
	}

	/**
	 * 地区名。
	 *
	 * @param string $region 地区码.
	 * @param int    $index  0 英文 / 1 简体 / 2 繁体.
	 * @return string
	 */
	private static function region_name( string $region, int $index ): string {
		return isset( self::REGIONS[ $region ] ) ? self::pick( self::REGIONS[ $region ], $index ) : '';
	}

	/**
	 * 从一行三列里取当前界面语言那一列。
	 *
	 * @param array{0: string, 1: string, 2: string} $row 名称行.
	 * @param int                                    $index 列号.
	 * @return string
	 */
	private static function pick( array $row, int $index ): string {
		return (string) ( $row[ $index ] ?? $row[0] );
	}

	/**
	 * 界面语言 → 名称表的列号。只有简体、繁体两种中文另存了译名，其余语言用英文名——
	 * 插件本身只有 en / zh_CN 两个语言包，硬塞第三种语言的译名没有校对来源。
	 *
	 * @param string $display_locale 界面语言.
	 * @return int
	 */
	private static function column( string $display_locale ): int {
		$display_locale = strtolower( str_replace( '-', '_', trim( $display_locale ) ) );
		if ( '' === $display_locale ) {
			return 0;
		}
		if ( 0 === strpos( $display_locale, 'zh' ) ) {
			// zh_TW / zh_HK / zh_Hant 用繁体列；zh_CN / zh_SG / zh_Hans 用简体列。
			$traditional = false !== strpos( $display_locale, 'hant' )
				|| in_array( $display_locale, array( 'zh_tw', 'zh_hk', 'zh_mo' ), true );
			return $traditional ? 2 : 1;
		}
		return 0;
	}
}
