<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Niche/vertical configuration.
 *
 * Everything the autopilot does is niche-aware:
 * - What to search for
 * - What products to review
 * - What job feeds to pull
 * - What keywords to score against
 *
 * Devs can register new verticals via the cep_register_niches filter.
 */
class NicheManager {

	/**
	 * Built-in niche presets.
	 * Each preset defines: keywords, review_types, article_categories, default sources, job_sources.
	 */
	private static array $presets = [
		'wordpress' => [
			'label'              => 'WordPress',
			'icon'               => '🔧',
			'palette'            => 'purple',
			'description'        => 'Plugins, themes, hosting, and everything in the WordPress ecosystem.',
			'keywords'           => 'wordpress,plugin,theme,gutenberg,woocommerce,wp,elementor,block editor,php,cms',
			'review_types'       => 'plugin,hosting,theme,service,tool',
			'article_categories' => 'core,security,hosting,community,industry,jobs',
			'review_sources'     => [
				'https://wordpress.org/plugins/feed/',
				'https://wordpress.org/themes/feed/',
				'https://wptavern.com/feed',
				'https://torquemag.io/feed/',
			],
			'job_sources'        => [
				'https://jobs.wordpress.net/feed/',
				'https://wpremotework.com/feed/',
				'https://remoteok.com/remote-wordpress-jobs.rss',
				'https://weworkremotely.com/categories/remote-full-stack-programming-jobs.rss',
			],
			'article_sources'    => [
				'https://wordpress.org/news/feed/',
				'https://make.wordpress.org/core/feed/',
				'https://wptavern.com/feed',
			],
			'search_queries'     => [
				'wordpress plugin review',
				'best wordpress plugins',
				'wordpress news',
				'wordpress security',
			],
		],
		'ecommerce' => [
			'label'              => 'eCommerce',
			'icon'               => '🛒',
			'palette'            => 'emerald',
			'description'        => 'Online stores, Shopify, WooCommerce, payment tools, and dropshipping.',
			'keywords'           => 'ecommerce,shopify,woocommerce,magento,bigcommerce,store,checkout,payment,dropshipping',
			'review_types'       => 'platform,plugin,service,tool,payment',
			'article_categories' => 'news,reviews,tutorials,strategies,tools',
			'review_sources'     => [
				'https://www.shopify.com/blog/rss.xml',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-ecommerce-jobs.rss',
			],
			'article_sources'    => [],
			'search_queries'     => [
				'ecommerce platform comparison',
				'best ecommerce tools',
			],
		],
		'saas' => [
			'label'              => 'SaaS / Software',
			'icon'               => '☁️',
			'palette'            => 'midnight',
			'description'        => 'SaaS tools, startups, automations, CRM, and B2B software.',
			'keywords'           => 'saas,software,startup,app,api,integration,automation,crm,erp,cloud',
			'review_types'       => 'software,service,tool,api,platform',
			'article_categories' => 'news,reviews,tutorials,trends,funding',
			'review_sources'     => [],
			'job_sources'        => [
				'https://remoteok.com/remote-saas-jobs.rss',
			],
			'article_sources'    => [],
			'search_queries'     => [
				'saas product review',
				'best business software',
			],
		],
		'marketing' => [
			'label'              => 'Digital Marketing',
			'icon'               => '📈',
			'palette'            => 'gold',
			'description'        => 'SEO, content marketing, email tools, PPC, analytics, and growth.',
			'keywords'           => 'seo,marketing,content,social media,email,ppc,analytics,conversion,funnel,growth',
			'review_types'       => 'tool,software,platform,service,agency',
			'article_categories' => 'seo,social,email,content,ads,analytics',
			'review_sources'     => [],
			'job_sources'        => [
				'https://remoteok.com/remote-marketing-jobs.rss',
			],
			'article_sources'    => [],
			'search_queries'     => [
				'best marketing tools',
				'digital marketing news',
			],
		],
		'lifestyle' => [
			'label'              => 'Lifestyle & Entertainment',
			'icon'               => '✨',
			'palette'            => 'pink',
			'description'        => 'Beauty, skincare, fashion, dating, wellness, and celebrity news.',
			'keywords'           => 'beauty,skincare,makeup,fashion,style,dating,relationship,wellness,self-care,celebrity,entertainment,fragrance,haircare,outfit,lifestyle,dating app,health,fitness',
			'review_types'       => 'beauty,skincare,fashion,dating-app,wellness,self-care,fragrance,haircare,supplement,streaming',
			'article_categories' => 'beauty,fashion,relationships,wellness,celebrity,entertainment,self-care',
			'review_sources'     => [
				// Beauty & skincare editorial review feeds
				'https://www.allure.com/feed/rss',
				'https://www.byrdie.com/rss',
				'https://intothegloss.com/feed/',
				'https://www.glamour.com/feed/rss',
				'https://www.cosmopolitan.com/style-beauty/beauty/rss/all.xml',
				// Wellness & self-care
				'https://www.wellandgood.com/feed',
				'https://greatist.com/feed',
				// Fashion & style
				'https://www.refinery29.com/en-us/beauty/rss.xml',
				// Tech/dating apps via TechCrunch tag
				'https://techcrunch.com/tag/dating-apps/feed/',
			],
			'job_sources'        => [],
			'article_sources'    => [
				'https://www.allure.com/feed/rss',
				'https://www.glamour.com/feed/rss',
				'https://www.wellandgood.com/feed',
				'https://www.refinery29.com/en-us/lifestyle/rss.xml',
				'https://www.cosmopolitan.com/lifestyle/rss/all.xml',
			],
			'search_queries'     => [
				'best skincare products review',
				'best beauty products',
				'best dating apps review',
				'wellness products review',
				'self care products',
				'best fashion accessories',
			],
		],
		'finance' => [
			'label'              => 'Personal Finance & Investing',
			'icon'               => '💰',
			'palette'            => 'emerald',
			'description'        => 'Investing, budgeting, credit cards, banking, and money tools.',
			'keywords'           => 'finance,investing,stocks,crypto,budgeting,credit card,banking,savings,retirement,mortgage,personal finance,money,wealth,fintech,etf,dividend',
			'review_types'       => 'app,platform,service,credit-card,broker,robo-advisor,bank,tool',
			'article_categories' => 'investing,budgeting,credit,banking,crypto,taxes,real-estate,tools',
			'review_sources'     => [
				'https://www.nerdwallet.com/blog/feed/',
				'https://www.investopedia.com/feeds/articles.rss',
				'https://feeds.feedburner.com/TheCollegeInvestor',
				'https://www.moneysavingexpert.com/feed/',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-finance-jobs.rss',
			],
			'article_sources'    => [
				'https://www.nerdwallet.com/blog/feed/',
				'https://www.investopedia.com/feeds/articles.rss',
				'https://finance.yahoo.com/news/rss',
			],
			'search_queries'     => [
				'best personal finance apps review',
				'best investing platforms',
				'best credit cards review',
				'personal finance tips',
				'best budgeting apps',
			],
		],
		'tech' => [
			'label'              => 'Tech & Gadgets',
			'icon'               => '💻',
			'palette'            => 'midnight',
			'description'        => 'Consumer tech, gadgets, apps, and software reviews.',
			'keywords'           => 'technology,gadgets,smartphone,laptop,tablet,ai,software,app,hardware,review,apple,android,windows,gaming,cpu,gpu,headphones,smartwatch',
			'review_types'       => 'smartphone,laptop,tablet,headphone,smartwatch,camera,software,app,accessory,smart-home',
			'article_categories' => 'news,reviews,ai,mobile,computing,audio,smart-home,security',
			'review_sources'     => [
				'https://www.theverge.com/rss/index.xml',
				'https://feeds.feedburner.com/TechCrunch',
				'https://www.engadget.com/rss.xml',
				'https://feeds.arstechnica.com/arstechnica/technology-lab',
				'https://www.zdnet.com/news/rss.xml',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-tech-jobs.rss',
			],
			'article_sources'    => [
				'https://www.theverge.com/rss/index.xml',
				'https://feeds.feedburner.com/TechCrunch',
				'https://www.wired.com/feed/rss',
			],
			'search_queries'     => [
				'best smartphone review',
				'best laptop review',
				'best tech gadgets',
				'technology news today',
				'best wireless headphones review',
			],
		],
		'gaming' => [
			'label'              => 'Gaming & Esports',
			'icon'               => '🎮',
			'palette'            => 'purple',
			'description'        => 'Video games, consoles, esports, and gaming gear reviews.',
			'keywords'           => 'gaming,video games,esports,console,playstation,xbox,nintendo,pc gaming,fps,rpg,battle royale,gaming headset,gaming chair,controller,twitch,steam',
			'review_types'       => 'game,console,peripherals,headset,chair,monitor,controller,platform',
			'article_categories' => 'news,reviews,esports,pc-gaming,console,mobile-gaming,hardware',
			'review_sources'     => [
				'https://www.ign.com/articles.rss',
				'https://feeds.feedburner.com/IGN_Games',
				'https://www.polygon.com/rss/index.xml',
				'https://kotaku.com/rss',
				'https://www.pcgamer.com/rss/',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-gamedev-jobs.rss',
			],
			'article_sources'    => [
				'https://www.ign.com/articles.rss',
				'https://www.polygon.com/rss/index.xml',
				'https://kotaku.com/rss',
			],
			'search_queries'     => [
				'best gaming headset review',
				'best gaming laptop review',
				'new game reviews',
				'gaming news',
				'best gaming chair review',
			],
		],
		'travel' => [
			'label'              => 'Travel & Adventure',
			'icon'               => '✈️',
			'palette'            => 'gold',
			'description'        => 'Destinations, travel gear, airlines, hotels, and apps.',
			'keywords'           => 'travel,destination,hotel,airline,airbnb,adventure,backpacking,vacation,resort,beach,hiking,road trip,travel insurance,visa,luggage,travel card',
			'review_types'       => 'hotel,airline,app,gear,luggage,insurance,credit-card,destination',
			'article_categories' => 'destinations,tips,gear,hotels,flights,food,culture,budget',
			'review_sources'     => [
				'https://www.nomadicmatt.com/feed/',
				'https://www.lonelyplanet.com/feed.rss',
				'https://www.travelandleisure.com/rss/all.xml',
				'https://thepointsguy.com/feed/',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-travel-jobs.rss',
			],
			'article_sources'    => [
				'https://www.nomadicmatt.com/feed/',
				'https://www.travelandleisure.com/rss/all.xml',
				'https://thepointsguy.com/feed/',
			],
			'search_queries'     => [
				'best travel destinations',
				'best travel apps review',
				'best travel credit card review',
				'best luggage review',
				'budget travel tips',
			],
		],
		'food' => [
			'label'              => 'Food & Dining',
			'icon'               => '🍕',
			'palette'            => 'crimson',
			'description'        => 'Restaurants, recipes, food delivery, kitchen tools.',
			'keywords'           => 'food,recipe,restaurant,cooking,kitchen,diet,nutrition,meal prep,food delivery,baking,vegan,keto,vegetarian,meal kit,coffee,chef,cuisine',
			'review_types'       => 'restaurant,kitchen-tool,appliance,delivery-service,meal-kit,cookbook,supplement',
			'article_categories' => 'recipes,restaurants,nutrition,kitchen,trends,drinks,diet',
			'review_sources'     => [
				'https://www.seriouseats.com/atom.xml',
				'https://www.bonappetit.com/feed/rss',
				'https://www.foodnetwork.com/fn-dish/rss.xml',
				'https://www.eater.com/rss/index.xml',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-hospitality-jobs.rss',
			],
			'article_sources'    => [
				'https://www.seriouseats.com/atom.xml',
				'https://www.bonappetit.com/feed/rss',
				'https://www.eater.com/rss/index.xml',
			],
			'search_queries'     => [
				'best food delivery apps review',
				'best kitchen appliances review',
				'best meal kit services',
				'healthy recipe ideas',
				'restaurant review',
			],
		],
		'fitness' => [
			'label'              => 'Health & Fitness',
			'icon'               => '💪',
			'palette'            => 'emerald',
			'description'        => 'Workouts, nutrition, supplements, fitness gear, and wellness apps.',
			'keywords'           => 'fitness,workout,gym,nutrition,supplement,protein,yoga,running,cycling,weight loss,muscle,strength training,wellness,health,marathon,triathlon,HIIT',
			'review_types'       => 'supplement,equipment,app,wearable,clothing,nutrition,program,gym',
			'article_categories' => 'workouts,nutrition,supplements,gear,wellness,running,yoga,weight-loss',
			'review_sources'     => [
				'https://www.bodybuilding.com/rss/articles',
				'https://greatist.com/feed',
				'https://www.runnersworld.com/rss/all.xml/',
				'https://www.menshealth.com/rss/all.xml/',
				'https://www.shape.com/rss/all.xml',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-health-jobs.rss',
			],
			'article_sources'    => [
				'https://greatist.com/feed',
				'https://www.runnersworld.com/rss/all.xml/',
				'https://www.menshealth.com/rss/all.xml/',
			],
			'search_queries'     => [
				'best fitness apps review',
				'best protein powder review',
				'best home gym equipment',
				'workout tips for beginners',
				'best fitness tracker review',
			],
		],
		'parenting' => [
			'label'              => 'Parenting & Family',
			'icon'               => '👶',
			'palette'            => 'pink',
			'description'        => 'Parenting advice, baby products, education, and family lifestyle.',
			'keywords'           => 'parenting,baby,toddler,kids,family,education,pregnancy,newborn,school,toys,childcare,motherhood,fatherhood,homeschool,stroller,car seat,breastfeeding',
			'review_types'       => 'stroller,car-seat,toy,app,supplement,baby-gear,monitor,clothing',
			'article_categories' => 'newborn,toddler,school-age,education,health,activities,gear,advice',
			'review_sources'     => [
				'https://www.parents.com/feeds/all.rss',
				'https://www.babycenter.com/rss/feeds/rss20babycenterarticles.xml',
				'https://www.romper.com/rss',
				'https://www.fatherly.com/feed/',
			],
			'job_sources'        => [],
			'article_sources'    => [
				'https://www.parents.com/feeds/all.rss',
				'https://www.fatherly.com/feed/',
				'https://www.romper.com/rss',
			],
			'search_queries'     => [
				'best baby products review',
				'best stroller review',
				'parenting tips',
				'best kids educational apps',
				'best car seat review',
			],
		],
		'pets' => [
			'label'              => 'Pets & Animals',
			'icon'               => '🐾',
			'palette'            => 'gold',
			'description'        => 'Pet care, food, accessories, health, and training.',
			'keywords'           => 'pets,dog,cat,puppy,kitten,pet food,pet care,animal,vet,pet health,training,grooming,aquarium,bird,rabbit,hamster,pet insurance',
			'review_types'       => 'food,supplement,toy,accessory,insurance,grooming,tech,service',
			'article_categories' => 'dogs,cats,health,nutrition,training,gear,exotic,news',
			'review_sources'     => [
				'https://www.dogster.com/feed',
				'https://www.catster.com/feed',
				'https://www.akc.org/expert-advice/rss.xml',
				'https://pets.webmd.com/default.htm?rss=1',
			],
			'job_sources'        => [],
			'article_sources'    => [
				'https://www.dogster.com/feed',
				'https://www.akc.org/expert-advice/rss.xml',
				'https://pets.webmd.com/default.htm?rss=1',
			],
			'search_queries'     => [
				'best dog food review',
				'best cat food review',
				'best pet insurance review',
				'pet care tips',
				'best dog toys review',
			],
		],
		'custom' => [
			'label'              => 'Custom (configure below)',
			'icon'               => '⚙️',
			'palette'            => 'purple',
			'description'        => 'Define your own niche with custom keywords, sources, and review types.',
			'keywords'           => '',
			'review_types'       => 'product,service,tool',
			'article_categories' => 'general',
			'review_sources'     => [],
			'job_sources'        => [],
			'article_sources'    => [],
			'search_queries'     => [],
		],
	];

	/**
	 * Get all registered niche presets.
	 */
	public static function get_presets(): array {
		return apply_filters( 'cep_register_niches', self::$presets );
	}

	/**
	 * Get the currently active niche config (preset merged with admin overrides).
	 */
	public static function get_active(): array {
		$vertical = Settings::get( 'niche_vertical', 'wordpress' );
		$presets  = self::get_presets();
		$preset   = $presets[ $vertical ] ?? $presets['custom'];

		// Admin overrides take priority over preset defaults
		return apply_filters( 'cep_active_niche', [
			'vertical'           => $vertical,
			'label'              => Settings::get( 'niche_name', $preset['label'] ),
			'keywords'           => Settings::get( 'niche_keywords', $preset['keywords'] ),
			'review_types'       => Settings::get( 'niche_review_types', $preset['review_types'] ),
			'article_categories' => Settings::get( 'niche_article_categories', $preset['article_categories'] ),
			'review_sources'     => self::merge_sources( 'review_discovery_sources', $preset['review_sources'] ),
			'job_sources'        => self::merge_sources( 'job_sources', $preset['job_sources'] ),
			'article_sources'    => self::merge_sources( 'article_sources', $preset['article_sources'] ),
			'search_queries'     => self::get_search_queries( $preset ),
		] );
	}

	/**
	 * Get keywords array for signal scoring.
	 */
	public static function get_keywords(): array {
		$raw = self::get_active()['keywords'];
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	/**
	 * Get review types array.
	 */
	public static function get_review_types(): array {
		$raw = self::get_active()['review_types'];
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	private static function merge_sources( string $setting_key, array $preset_sources ): array {
		$custom = Settings::get( $setting_key, '' );
		$custom_arr = $custom
			? array_filter( array_map( 'trim', explode( "\n", $custom ) ) )
			: [];
		return array_unique( array_merge( $preset_sources, $custom_arr ) );
	}

	private static function get_search_queries( array $preset ): array {
		$custom = Settings::get( 'niche_search_queries', '' );
		$custom_arr = $custom
			? array_filter( array_map( 'trim', explode( "\n", $custom ) ) )
			: [];
		return array_unique( array_merge( $preset['search_queries'] ?? [], $custom_arr ) );
	}
}
