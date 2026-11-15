#!/usr/bin/env python3
"""Build the WordPress.org plugin ZIP. The plugin is built for WordPress.org only; there is no GitHub edition.

- the main file ships under the slug (thrg-php-checker.php) instead of index.php
- no Plugin URI / Update URI, no updater, no bundled update library
- store graphics stay out of the ZIP (they live in .wordpress-org/ for the SVN assets folder)
- a version with a suffix, such as 1.2.8-prerelease, builds a test ZIP that must not be uploaded to WordPress.org
"""
from pathlib import Path
import argparse, fnmatch, hashlib, re, zipfile

p = argparse.ArgumentParser()
p.add_argument( '--output', type=Path )
p.add_argument( '--wporg', action='store_true', help='Accepted for compatibility; the build always produces the WordPress.org package.' )
a = p.parse_args()
a.wporg = True

root    = Path( __file__ ).resolve().parents[1]
main    = next( f for f in root.glob( '*.php' ) if re.search( r'Plugin Name:', f.read_text() ) )
slug    = main.stem if main.stem != 'index' else root.name
version = re.search( r'Version:\s*([0-9][0-9A-Za-z.\-]*)', main.read_text() ).group( 1 )
global_version = re.search( r"const\s+THRG_PHP_CHECKER_VERSION\s*=\s*'([^']+)'", main.read_text() ).group( 1 )
controller_version = re.search( r"const\s+VERSION\s*=\s*'([^']+)'", ( root / 'includes/class-thrg-php-checker.php' ).read_text() ).group( 1 )
assert global_version == controller_version == version, 'Plugin header, global version and controller fallback version must agree'

# The readme must agree with the plugin version before anything is built.
readme_text_src = ( root / 'readme.txt' ).read_text( encoding='utf-8' )
readme_stable   = re.search( r'^Stable tag:\s*(\S+)', readme_text_src, flags=re.MULTILINE ).group( 1 )
assert readme_stable == version, f'readme.txt Stable tag {readme_stable} does not match Version {version}'
assert f'= {version} =' in readme_text_src.split( '== Changelog ==', 1 )[1], f'readme.txt changelog has no entry for {version}'

default_name = f'{slug}.{version}.wporg.zip'
output       = a.output or ( root / 'dist' / default_name )
output.parent.mkdir( parents=True, exist_ok=True )

excluded = { '.git', '.github', '.review', 'tests', 'scripts', 'dist', 'releases', 'node_modules', 'docs', '.wordpress-org', 'review-work' }
excluded_files = { '.DS_Store', '.gitignore', '.editorconfig', '.distignore', 'phpcs.xml.dist', 'README.md', 'PROJECT.md', 'PROJECT_UPDATES.md', 'SECURITY.md', 'REFACTOR.md' }
excluded_paths = [
    'assets/banner-*.png',
    'assets/icon-*.png',
]

patterns = []
if ( root / '.distignore' ).exists():
    patterns = [ s.strip().lstrip( '/' ) for s in ( root / '.distignore' ).read_text().splitlines() if s.strip() and not s.startswith( '#' ) ]

files = []
for f in root.rglob( '*' ):
    if not f.is_file():
        continue
    rel = f.relative_to( root )
    rel_posix = str( rel ).replace( '\\', '/' )
    is_bundled = len( rel.parts ) >= 2 and rel.parts[0] == 'vendor' and rel.parts[1] == 'thrg-scan-engine'
    if not is_bundled and ( rel.parts[0] in excluded or rel.parts[0] == 'vendor' or f.name in excluded_files or any( fnmatch.fnmatch( str( rel ), x ) or str( rel ).startswith( x.rstrip( '/' ) + '/' ) for x in patterns ) ):
        continue
    if any( fnmatch.fnmatch( rel_posix, x ) for x in excluded_paths ):
        continue
    if f.name == '.DS_Store':
        continue
    files.append( f )

main_in_zip = slug + '.php'

with zipfile.ZipFile( output, 'w', zipfile.ZIP_DEFLATED ) as z:
    for f in sorted( files ):
        rel_str = str( f.relative_to( root ) ).replace( '\\', '/' )
        data               = f.read_bytes()
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
        assert readme_text == readme_text_src
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

digest = hashlib.sha256( output.read_bytes() ).hexdigest()
output.with_suffix( output.suffix + '.sha256' ).write_text( digest + '  ' + output.name + '\n' )
print( output )
print( digest )
if '-' in version:
    print( f'NOTE: {version} is a pre-release test build; do not upload it to WordPress.org.' )
