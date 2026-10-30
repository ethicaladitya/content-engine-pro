<?php
/**
 * Job import helpers. Read-only; run on any install with the plugin active:
 *   wp eval-file tests/test-job-helpers.php
 */
use ContentEnginePro\Jobs\JobAggregator;
use ContentEnginePro\Jobs\CareerScraper;

// Production PHP compiles assert() out, so check explicitly.
function cep_t( bool $ok ): void {
	static $n = 0;
	$n++;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL check #$n\n" );
		exit( 1 );
	}
}

cep_t( JobAggregator::strip_company_prefix( 'Salesloft: HR Enablement Lead', 'Salesloft' ) === 'HR Enablement Lead' );
cep_t( JobAggregator::strip_company_prefix( 'Vested, Inc.: Senior Software Engineer', 'Vested, Inc.' ) === 'Senior Software Engineer' );
cep_t( JobAggregator::strip_company_prefix( 'Senior Systems Engineer', 'Servebolt' ) === 'Senior Systems Engineer' );
cep_t( JobAggregator::strip_company_prefix( 'Acme:', 'Acme' ) === 'Acme:' );

cep_t( JobAggregator::build_job_slug( 'Salesloft', 'Salesloft: HR Enablement Lead' ) === 'salesloft-hr-enablement-lead' );
cep_t( JobAggregator::build_job_slug( 'Salesloft', 'HR Enablement Lead' ) === 'salesloft-hr-enablement-lead' );
cep_t( JobAggregator::build_job_slug( 'Fueled', 'Fueled Design Lead' ) === 'fueled-design-lead' );
cep_t( JobAggregator::build_job_slug( 'Kinsta', 'Kinstant Search Engineer' ) === 'kinsta-kinstant-search-engineer' );

$call = static function ( string $method, ...$args ) {
	$m = new ReflectionMethod( CareerScraper::class, $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
};

// rtCamp card: title, location and team in separate elements.
$card  = '<a href="/job/seo-executive/"><div><p>SEO Executive</p></div><div><span>Remote</span><span>Marketing</span></div></a>';
$links = $call( 'extract_job_links', "<html><body>$card</body></html>", 'https://careers.rtcamp.com/' );
cep_t( 'SEO Executive' === $links[0]['title'] && 'https://careers.rtcamp.com/job/seo-executive/' === $links[0]['url'] );
// Plain link text is unchanged.
$links = $call( 'extract_job_links', '<a href="/jobs/wordpress-developer/">WordPress Developer</a>', 'https://example.com/careers/' );
cep_t( 'WordPress Developer' === $links[0]['title'] );

// wpremotework (Breakdance): the job body, not the related-jobs sidebar.
$body = str_repeat( 'Servebolt is a managed hosting company. ', 5 );
$page = '<div class="entry-content">' . str_repeat( 'Fueled Senior Systems Engineer DevOps Remote. ', 5 ) . '</div>'
	. '<div class="bde-rich-text breakdance-rich-text-styles"><h2>About</h2><p>' . $body . '</p></div>';
cep_t( false !== strpos( $call( 'extract_body_html', "<html><body>$page</body></html>" ), 'managed hosting' ) );

echo "ok\n";
