#!/usr/bin/env python3
"""Build a plugin release ZIP (GitHub release by default, or --wporg for WordPress.org submission).

The WordPress.org build follows the Stickies WordPress.org edition:
- main file named after the slug (thrg-php-checker.php) instead of index.php
- no Plugin URI / Update URI, no updater, no bundled update library
- no View details override or companion tab
- readme.txt comes from readme-wporg.txt
- store graphics stay out of the ZIP (they live in .wordpress-org/ for the SVN assets folder)
"""
from pathlib import Path
import argparse, fnmatch, hashlib, re, zipfile

p = argparse.ArgumentParser()
p.add_argument( '--output', type=Path )
p.add_argument( '--wporg', action='store_true', help='Build a WordPress.org-compliant ZIP without updater code, Update URI or Plugin URI.' )
a = p.parse_args()

root    = Path( __file__ ).resolve().parents[1]
main    = next( f for f in root.glob( '*.php' ) if re.search( r'Plugin Name:', f.read_text() ) )
slug    = main.stem if main.stem != 'index' else root.name
version = re.search( r'Version:\s*([0-9.]+)', main.read_text() ).group( 1 )
global_version = re.search( r"const\s+THRG_PHP_CHECKER_VERSION\s*=\s*'([^']+)'", main.read_text() ).group( 1 )
controller_version = re.search( r"const\s+VERSION\s*=\s*'([^']+)'", ( root / 'includes/class-thrg-php-checker.php' ).read_text() ).group( 1 )
assert global_version == controller_version == version, 'Plugin header, global version and controller fallback version must agree'

# The two readmes must agree on version and changelog headings before anything is built.
def readme_info( path: Path ):
    text   = path.read_text( encoding='utf-8' )
    stable = re.search( r'^Stable tag:\s*(\S+)', text, flags=re.MULTILINE ).group( 1 )
    log    = text.split( '== Changelog ==', 1 )[1]
    return stable, re.findall( r'^= ([^=\n]+?) =\s*$', log, flags=re.MULTILINE )

github_stable, github_log = readme_info( root / 'readme.txt' )
wporg_stable, wporg_log   = readme_info( root / 'readme-wporg.txt' )
assert github_stable == version, f'readme.txt Stable tag {github_stable} does not match Version {version}'
assert wporg_stable == version, f'readme-wporg.txt Stable tag {wporg_stable} does not match Version {version}'
assert github_log == wporg_log, 'readme.txt and readme-wporg.txt changelog headings differ'

default_name = f'{slug}.{version}.wporg.zip' if a.wporg else f'{slug}.{version}.zip'
output       = a.output or ( root / 'dist' / default_name )
output.parent.mkdir( parents=True, exist_ok=True )

excluded = { '.git', '.github', '.review', 'tests', 'scripts', 'dist', 'releases', 'node_modules', 'docs', '.wordpress-org', 'review-work' }
excluded_files = { '.DS_Store', '.gitignore', '.editorconfig', '.distignore', 'phpcs.xml.dist', 'README.md', 'PROJECT.md', 'PROJECT_UPDATES.md', 'SECURITY.md', 'REFACTOR.md', 'readme-wporg.txt' }
excluded_paths = []
if a.wporg:
    excluded.add( 'plugin-update-checker' )
    excluded_files.update( { 'class-thrg-pc-updater.php', 'class-thrg-pc-update-notices.php', 'companion-plugins.php' } )
    excluded_paths = [
        'assets/banner-*.png',
        'assets/icon-*.png',
        'assets/brand/thrg-details.css',
        'assets/css/thrg_pc-plugin-details.css',
    ]

patterns = []
if ( root / '.distignore' ).exists():
    patterns = [ s.strip().lstrip( '/' ) for s in ( root / '.distignore' ).read_text().splitlines() if s.strip() and not s.startswith( '#' ) ]

def replace_once( text: str, old: str, new: str, label: str ) -> str:
    assert old in text, f'wporg transform: {label} not found'
    return text.replace( old, new, 1 )

def transform_wporg( rel_str: str, raw: bytes ) -> bytes:
    if not a.wporg:
        return raw
    if rel_str == 'index.php':
        text = raw.decode( 'utf-8' )
        text = re.sub( r'^\s*\*\s*Plugin URI:.*\n', '', text, flags=re.MULTILINE )
        text = re.sub( r'^\s*\*\s*Update URI:.*\n', '', text, flags=re.MULTILINE )
        text = re.sub(
            r'/\*\s*\n\s*\* GitHub release updates\..*?if\s*\(\s*!\s*defined\(\s*\'THRG_PC_SKIP_UPDATER\'\s*\)\s*\)\s*\{\n.*?\n\}\n\n',
            '',
            text,
            flags=re.DOTALL,
        )
        text = replace_once( text, "require_once __DIR__ . '/includes/companion-plugins.php';\n", '', 'companion require' )
        return text.encode( 'utf-8' )
    if rel_str == 'includes/class-thrg-pc-engine.php':
        text = raw.decode( 'utf-8' )
        text = re.sub(
            r'        if\s*\(\s*\'plugin-update-checker\'\s*===\s*\$lower\s*&&\s*is_file\(\s*\$root\s*\.\s*\'/includes/class-thrg-php-checker\.php\'\s*\)\s*\)\s*\{\n            return true;\n        \}\n',
            '',
            text,
        )
        return text.encode( 'utf-8' )
    if rel_str == 'uninstall.php':
        text = raw.decode( 'utf-8' )
        text = replace_once(
            text,
            "\ndelete_site_option( 'external_updates-' . basename( __DIR__ ) );\ndelete_site_transient( 'thrg_pc_github_limit' );\n",
            '',
            'updater cleanup in uninstall.php',
        )
        return text.encode( 'utf-8' )
    return raw

files = []
for f in root.rglob( '*' ):
    if not f.is_file():
        continue
    rel = f.relative_to( root )
    rel_posix = str( rel ).replace( '\\', '/' )
    is_bundled = ( not a.wporg and rel.parts[0] == 'plugin-update-checker' ) or ( len( rel.parts ) >= 2 and rel.parts[0] == 'vendor' and rel.parts[1] == 'thrg-scan-engine' )
    if not is_bundled and ( rel.parts[0] in excluded or rel.parts[0] == 'vendor' or f.name in excluded_files or any( fnmatch.fnmatch( str( rel ), x ) or str( rel ).startswith( x.rstrip( '/' ) + '/' ) for x in patterns ) ):
        continue
    if any( fnmatch.fnmatch( rel_posix, x ) for x in excluded_paths ):
        continue
    if f.name == '.DS_Store':
        continue
    files.append( f )

main_in_zip = slug + '.php' if a.wporg else 'index.php'

with zipfile.ZipFile( output, 'w', zipfile.ZIP_DEFLATED ) as z:
    for f in sorted( files ):
        rel_str = str( f.relative_to( root ) ).replace( '\\', '/' )
        if a.wporg and rel_str == 'readme.txt':
            data = ( root / 'readme-wporg.txt' ).read_bytes()
        else:
            data = transform_wporg( rel_str, f.read_bytes() )
        arcname            = slug + '/' + ( main_in_zip if rel_str == 'index.php' else rel_str )
        info               = zipfile.ZipInfo.from_file( f, arcname )
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr( info, data )

with zipfile.ZipFile( output ) as z:
    names = z.namelist()
    assert z.testzip() is None
    assert all( ( info.external_attr >> 16 ) & 0o7777 == 0o644 for info in z.infolist() ), 'Packaged files must use 0644 permissions'
    assert { n.split( '/' )[0] for n in names } == { slug }
    assert slug + '/vendor/thrg-scan-engine/php-codesniffer/autoload.php' in names
    assert slug + '/' + main_in_zip in names
    if a.wporg:
        main_text   = z.read( slug + '/' + main_in_zip ).decode( 'utf-8' )
        readme_text = z.read( slug + '/readme.txt' ).decode( 'utf-8' )
        assert slug + '/index.php' not in names, 'wporg ZIP must name its main file after the slug'
        assert not any( n.startswith( slug + '/plugin-update-checker/' ) for n in names ), 'wporg ZIP must not contain plugin-update-checker'
        assert slug + '/includes/class-thrg-pc-updater.php' not in names
        assert slug + '/includes/class-thrg-pc-update-notices.php' not in names
        assert slug + '/includes/companion-plugins.php' not in names, 'wporg ZIP must not ship the companion tab'
        assert 'companion-plugins' not in main_text
        assert 'Plugin URI:' not in main_text and 'Update URI:' not in main_text
        assert 'THRG_PC_SKIP_UPDATER' not in main_text and 'plugin-update-checker' not in main_text
        assert 'external_updates' not in z.read( slug + '/uninstall.php' ).decode( 'utf-8' )
        assert not [ n for n in names if n.startswith( slug + '/assets/' ) and re.search( r'/(banner|icon)-[0-9x]+\.png$', n ) ], 'store graphics belong in .wordpress-org/'
        assert readme_text == ( root / 'readme-wporg.txt' ).read_text( encoding='utf-8' )
        assert 'Plugin Update Checker' not in readme_text and 'Update URI' not in readme_text
        assert re.search( r'^Contributors: hackrepair, tvcnet$', readme_text, flags=re.MULTILINE )
        assert '== External services ==' in readme_text
        assert 'security reporting' not in readme_text.lower() and 'jim@hackrepair.com' not in readme_text
        # WordPress.org review gates (lessons from earlier submissions; Plugin Check does not enforce these).
        def header_value( text: str, label: str ) -> str:
            m = re.search( r'^[ \t*]*' + re.escape( label ) + r':\s*(\S+)', text, flags=re.MULTILINE )
            assert m, f'wporg gate: {label} missing'
            return m.group( 1 )
        root_php = [ n for n in names if n.count( '/' ) == 1 and n.endswith( '.php' ) ]
        with_header = [ n for n in root_php if 'Plugin Name:' in z.read( n ).decode( 'utf-8' ) ]
        assert with_header == [ slug + '/' + slug + '.php' ], f'wporg gate: only {slug}.php may carry the plugin header, found {with_header}'
        req_header = header_value( main_text, 'Requires at least' )
        req_readme = header_value( readme_text, 'Requires at least' )
        assert req_header == req_readme, f'wporg gate: Requires at least differs (header {req_header}, readme {req_readme})'
        assert re.fullmatch( r'\d+\.\d', req_header ), f'wporg gate: Requires at least must be a major version such as 6.4, not {req_header}'
        assert header_value( main_text, 'Requires PHP' ) == header_value( readme_text, 'Requires PHP' ), 'wporg gate: Requires PHP differs'
        assert re.fullmatch( r'\d+\.\d(\.\d+)?', header_value( readme_text, 'Tested up to' ) ), 'wporg gate: Tested up to format'
        assert header_value( main_text, 'Version' ) == version == header_value( readme_text, 'Stable tag' ), 'wporg gate: Version and Stable tag differ'
        assert header_value( main_text, 'Text Domain' ) == slug, 'wporg gate: Text Domain must equal the slug'
        tags_line = re.search( r'^Tags:\s*(.+)$', readme_text, flags=re.MULTILINE ).group( 1 )
        assert len( [ t for t in tags_line.split( ',' ) if t.strip() ] ) <= 5, 'wporg gate: more than 5 tags'
        after_headers = readme_text.split( 'License URI:', 1 )[1].split( '\n', 1 )[1]
        short_desc    = next( line for line in after_headers.splitlines() if line.strip() )
        assert len( short_desc.strip() ) <= 150, f'wporg gate: short description is {len( short_desc.strip() )} characters (limit 150)'
        for section in ( '== Description ==', '== Installation ==', '== Frequently Asked Questions ==', '== Changelog ==' ):
            assert section in readme_text, f'wporg gate: readme section {section} missing'
        for n in names:
            if not n.endswith( ( '.php', '.txt', '.md', '.js', '.css', '.json', '.svg', '.html', '.xml' ) ):
                continue
            blob = z.read( n ).decode( 'utf-8', errors='ignore' ).lower()
            assert not re.search( r'\x63laude|\x61nthropic|co-authored', blob ), f'wporg gate: forbidden word in {n}'
    else:
        assert slug + '/includes/companion-plugins.php' in names
        assert slug + '/readme-wporg.txt' not in names
        for f in ( root / 'plugin-update-checker' ).rglob( '*' ):
            if f.is_file() and f.name != '.DS_Store':
                assert z.read( slug + '/' + str( f.relative_to( root ) ) ) == f.read_bytes()

digest = hashlib.sha256( output.read_bytes() ).hexdigest()
output.with_suffix( output.suffix + '.sha256' ).write_text( digest + '  ' + output.name + '\n' )
print( output )
print( digest )
