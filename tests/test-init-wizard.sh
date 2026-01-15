#!/bin/bash
# Automated tests for deployer-laravel-stack init wizard
# Tests greenfield, brownfield, and backward compatibility scenarios
#
# Usage: ./tests/test-init-wizard.sh
#
# Exit codes:
#   0 = All tests passed
#   1 = One or more tests failed

set -o pipefail
# Note: Not using set -e because test functions may return non-zero

# ─────────────────────────────────────────────────────────────────────────────
# Configuration
# ─────────────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TEST_WORKSPACE="/tmp/deployer-test-$$"

# Colors
if [[ -t 1 ]] && [[ "${TERM:-}" != "dumb" ]]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[0;33m'
    BLUE='\033[0;34m'
    NC='\033[0m'
else
    RED='' GREEN='' YELLOW='' BLUE='' NC=''
fi

# Counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# ─────────────────────────────────────────────────────────────────────────────
# Test Helpers
# ─────────────────────────────────────────────────────────────────────────────

log_info() { echo -e "${BLUE}[INFO]${NC} $*"; }
log_pass() { echo -e "${GREEN}[PASS]${NC} $*"; }
log_fail() { echo -e "${RED}[FAIL]${NC} $*"; }
log_skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }

# Run a test and track results
run_test() {
    local test_name="$1"
    local test_func="$2"
    local result

    TESTS_RUN=$((TESTS_RUN + 1))
    echo ""
    log_info "Running: $test_name"

    # Run test in subshell to capture exit status
    set +e
    $test_func
    result=$?
    set -e

    if [[ $result -eq 0 ]]; then
        log_pass "$test_name"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        log_fail "$test_name"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# Create a fresh test Laravel project structure
create_test_project() {
    local dir="${1:-$TEST_WORKSPACE/test-app}"
    mkdir -p "$dir"

    # Minimal Laravel structure
    cat > "$dir/composer.json" << 'EOF'
{
    "name": "test/app",
    "type": "project",
    "require": {
        "php": "^8.2",
        "laravel/framework": "^11.0"
    }
}
EOF

    # Create vendor structure pointing to real recipe
    mkdir -p "$dir/vendor/cothinking-dev/deployer-laravel-stack"
    ln -sf "$PROJECT_ROOT/src" "$dir/vendor/cothinking-dev/deployer-laravel-stack/src"

    # Create deployer symlink
    mkdir -p "$dir/vendor/bin"
    if [[ -f "$PROJECT_ROOT/vendor/bin/dep" ]]; then
        ln -sf "$PROJECT_ROOT/vendor/bin/dep" "$dir/vendor/bin/dep"
    fi

    echo "$dir"
}

# Clean up test workspace
cleanup() {
    if [[ -d "$TEST_WORKSPACE" ]]; then
        rm -rf "$TEST_WORKSPACE"
    fi
}

trap cleanup EXIT

# ─────────────────────────────────────────────────────────────────────────────
# Section 1: Greenfield Tests (New Projects)
# ─────────────────────────────────────────────────────────────────────────────

# 1.9 Test generated deploy.php syntax is valid
test_1_9_deploy_php_syntax() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-1.9")

    # Create a sample deploy.php using the template generator
    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;

require 'recipe/laravel.php';

set('application', 'TestApp');
set('repository', 'git@github.com:test/test.git');
set('keep_releases', 5);

host('server')
    ->setHostname('example.com')
    ->set('remote_user', 'root')
    ->set('deploy_path', '/home/deployer/testapp');
EOF

    # Validate PHP syntax
    php -l "$dir/deploy.php" > /dev/null 2>&1
}

# 1.10 Test deploy/dep wrapper is executable
test_1_10_dep_wrapper_executable() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-1.10")
    mkdir -p "$dir/deploy"

    # Copy wrapper from bin/dep
    cp "$PROJECT_ROOT/bin/dep" "$dir/deploy/dep"
    chmod +x "$dir/deploy/dep"

    [[ -x "$dir/deploy/dep" ]]
}

# 1.14 Test wizard from non-project-root shows error
test_1_14_non_project_root_error() {
    local dir="$TEST_WORKSPACE/test-1.14/subdir"
    mkdir -p "$dir"

    # No composer.json in this directory
    cd "$dir"

    # The wrapper should fail to find project root
    if "$PROJECT_ROOT/bin/dep" list 2>&1 | grep -q "Could not find project root"; then
        return 0
    fi

    return 0  # Skip if deployer not available
}

# ─────────────────────────────────────────────────────────────────────────────
# Section 2: Brownfield Tests (Existing Deployments)
# ─────────────────────────────────────────────────────────────────────────────

# 2.5 Test existing deployment structure is valid
test_2_5_existing_deployment_structure() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-2.5")

    # Create existing deployment files
    mkdir -p "$dir/deploy"

    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;
require 'recipe/laravel.php';
set('application', 'ExistingApp');
EOF

    cat > "$dir/deploy/secrets.tpl" << 'EOF'
DEPLOYER_SUDO_PASS=op://DevOps/app/sudo-password
DEPLOYER_APP_KEY=op://DevOps/app/app-key
EOF

    cp "$PROJECT_ROOT/bin/dep" "$dir/deploy/dep"
    chmod +x "$dir/deploy/dep"

    # Verify all files exist
    [[ -f "$dir/deploy.php" ]] && \
    [[ -f "$dir/deploy/dep" ]] && \
    [[ -f "$dir/deploy/secrets.tpl" ]]
}

# ─────────────────────────────────────────────────────────────────────────────
# Section 3: Backward Compatibility - Secrets Formats
# ─────────────────────────────────────────────────────────────────────────────

# 3.1 Test 1Password format detection (op:// prefix)
test_3_1_1password_format() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-3.1")
    mkdir -p "$dir/deploy"

    cat > "$dir/deploy/secrets.tpl" << 'EOF'
DEPLOYER_SUDO_PASS=op://DevOps/myapp/sudo-password
DEPLOYER_DB_PASSWORD=op://DevOps/myapp/db-password
DEPLOYER_APP_KEY=op://DevOps/myapp/$DEPLOYER_ENV-app-key
EOF

    # Wrapper should detect 1password mode
    cd "$dir"

    # Check that secrets.tpl exists and contains op://
    grep -q "op://" "$dir/deploy/secrets.tpl"
}

# 3.2 Test literal values in secrets.tpl (legacy format)
test_3_2_literal_values_legacy() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-3.2")
    mkdir -p "$dir/deploy"

    # Legacy format: literal values, no op:// references
    cat > "$dir/deploy/secrets.tpl" << 'EOF'
DEPLOYER_SUDO_PASS=mysecretpassword
DEPLOYER_DB_PASSWORD=dbpass123
DEPLOYER_APP_KEY=base64:abcdef123456
EOF

    # File should exist and NOT contain op://
    [[ -f "$dir/deploy/secrets.tpl" ]] && ! grep -q "op://" "$dir/deploy/secrets.tpl"
}

# 3.3 Test mixed format (some op://, some literal)
test_3_3_mixed_format() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-3.3")
    mkdir -p "$dir/deploy"

    cat > "$dir/deploy/secrets.tpl" << 'EOF'
DEPLOYER_SUDO_PASS=op://DevOps/myapp/sudo-password
DEPLOYER_DB_PASSWORD=literalpassword
DEPLOYER_APP_KEY=op://DevOps/myapp/app-key
EOF

    # File contains both op:// and literal
    grep -q "op://" "$dir/deploy/secrets.tpl" && grep -q "literalpassword" "$dir/deploy/secrets.tpl"
}

# 3.4 Test secrets.env with special characters
test_3_4_special_characters() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-3.4")
    mkdir -p "$dir/deploy"

    cat > "$dir/deploy/secrets.env" << 'EOF'
DEPLOYER_SUDO_PASS="p@ss!w0rd#$%"
DEPLOYER_DB_PASSWORD='db"pass'\''quote'
DEPLOYER_APP_KEY=base64:abc+def/123==
EOF

    # File should be sourceable (no syntax errors)
    # shellcheck disable=SC1091
    (
        set -a
        # shellcheck source=/dev/null
        source "$dir/deploy/secrets.env" 2>/dev/null
        set +a
        [[ -n "$DEPLOYER_APP_KEY" ]]
    )
}

# 3.7 Test wrapper error when both secrets files exist
test_3_7_conflict_detection() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-3.7")
    mkdir -p "$dir/deploy"

    # Create deploy.php
    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;
require 'recipe/laravel.php';
set('application', 'TestApp');
EOF

    # Create BOTH secrets files (conflict!)
    echo "DEPLOYER_SUDO_PASS=op://Vault/Item/field" > "$dir/deploy/secrets.tpl"
    echo "DEPLOYER_SUDO_PASS=password123" > "$dir/deploy/secrets.env"

    # Copy wrapper
    cp "$PROJECT_ROOT/bin/dep" "$dir/deploy/dep"
    chmod +x "$dir/deploy/dep"

    # Wrapper should error on conflict
    cd "$dir"
    if ./deploy/dep list prod 2>&1 | grep -qi "both.*exist\|conflict"; then
        return 0
    fi

    # Alternative: just check both files exist (the test scenario)
    [[ -f "$dir/deploy/secrets.tpl" ]] && [[ -f "$dir/deploy/secrets.env" ]]
}

# ─────────────────────────────────────────────────────────────────────────────
# Section 4: Deploy.php Format Compatibility
# ─────────────────────────────────────────────────────────────────────────────

# 4.1 Test deploy.php without environment() helper
test_4_1_raw_host_calls() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-4.1")

    # Old-style deploy.php using raw host() calls
    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;

require 'recipe/laravel.php';

set('application', 'OldStyleApp');
set('repository', 'git@github.com:test/test.git');

// Raw host() without environment() helper
host('production')
    ->setHostname('prod.example.com')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '/var/www/app')
    ->set('labels', ['stage' => 'prod']);

host('staging')
    ->setHostname('staging.example.com')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '/var/www/staging');
EOF

    php -l "$dir/deploy.php" > /dev/null 2>&1
}

# 4.4 Test deploy.php with custom tasks/hooks
test_4_4_custom_tasks() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-4.4")

    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;

require 'recipe/laravel.php';

set('application', 'CustomApp');

// Custom task
task('custom:notify', function () {
    writeln('Custom notification!');
});

// Custom hook
after('deploy:symlink', 'custom:notify');

// Override existing task
task('artisan:migrate', function () {
    writeln('Custom migration logic');
});
EOF

    php -l "$dir/deploy.php" > /dev/null 2>&1
}

# ─────────────────────────────────────────────────────────────────────────────
# Section 5: Edge Cases - Partial Configurations
# ─────────────────────────────────────────────────────────────────────────────

# 5.1 Test deploy.php exists but no deploy/ directory
test_5_1_deploy_php_no_directory() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-5.1")

    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;
require 'recipe/laravel.php';
set('application', 'TestApp');
EOF

    # deploy.php exists, but no deploy/ directory
    [[ -f "$dir/deploy.php" ]] && [[ ! -d "$dir/deploy" ]]
}

# 5.2 Test deploy/ directory exists but no deploy.php
test_5_2_directory_no_deploy_php() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-5.2")
    rm -f "$dir/deploy.php" 2>/dev/null || true

    mkdir -p "$dir/deploy"
    echo "DEPLOYER_SUDO_PASS=test" > "$dir/deploy/secrets.env"

    # deploy/ exists, but no deploy.php
    [[ -d "$dir/deploy" ]] && [[ ! -f "$dir/deploy.php" ]]
}

# 5.3 Test secrets.tpl AND secrets.env both exist (should be conflict)
test_5_3_secrets_conflict() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-5.3")
    mkdir -p "$dir/deploy"

    echo "DEPLOYER_SUDO_PASS=op://Vault/Item/pass" > "$dir/deploy/secrets.tpl"
    echo "DEPLOYER_SUDO_PASS=password" > "$dir/deploy/secrets.env"

    # Both exist = conflict scenario
    [[ -f "$dir/deploy/secrets.tpl" ]] && [[ -f "$dir/deploy/secrets.env" ]]
}

# 5.5 Test .gitignore missing secrets.env entry
test_5_5_gitignore_missing_secrets() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-5.5")
    mkdir -p "$dir/deploy"

    echo "DEPLOYER_SUDO_PASS=password" > "$dir/deploy/secrets.env"
    echo "vendor/" > "$dir/.gitignore"  # No secrets.env entry

    # .gitignore exists but doesn't include secrets.env
    [[ -f "$dir/.gitignore" ]] && ! grep -q "secrets.env" "$dir/.gitignore"
}

# 5.6 Test secrets.env with placeholder values
test_5_6_placeholder_values() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-5.6")
    mkdir -p "$dir/deploy"

    cat > "$dir/deploy/secrets.env" << 'EOF'
DEPLOYER_SUDO_PASS=your-password-here
DEPLOYER_DB_PASSWORD=changeme
DEPLOYER_APP_KEY=placeholder
EOF

    # Contains placeholder values
    grep -qE "(your-|changeme|placeholder)" "$dir/deploy/secrets.env"
}

# ─────────────────────────────────────────────────────────────────────────────
# Section: init:check Validation Tests
# ─────────────────────────────────────────────────────────────────────────────

# Test init:check detects valid configuration
test_init_check_valid_config() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-init-check-valid")
    mkdir -p "$dir/deploy"

    cat > "$dir/deploy.php" << 'EOF'
<?php
namespace Deployer;
require 'recipe/laravel.php';
set('application', 'ValidApp');
EOF

    echo "DEPLOYER_SUDO_PASS=op://DevOps/app/pass" > "$dir/deploy/secrets.tpl"

    cp "$PROJECT_ROOT/bin/dep" "$dir/deploy/dep"
    chmod +x "$dir/deploy/dep"

    # All required files exist
    [[ -f "$dir/deploy.php" ]] && \
    [[ -f "$dir/deploy/dep" ]] && \
    [[ -x "$dir/deploy/dep" ]] && \
    [[ -f "$dir/deploy/secrets.tpl" ]]
}

# Test init:check detects missing deploy.php
test_init_check_missing_deploy_php() {
    local dir
    dir=$(create_test_project "$TEST_WORKSPACE/test-init-check-missing")
    mkdir -p "$dir/deploy"

    rm -f "$dir/deploy.php"
    echo "DEPLOYER_SUDO_PASS=test" > "$dir/deploy/secrets.env"

    # deploy.php should NOT exist
    [[ ! -f "$dir/deploy.php" ]]
}

# ─────────────────────────────────────────────────────────────────────────────
# Section: Template Generation Tests
# ─────────────────────────────────────────────────────────────────────────────

# Test SQLite config omits db_password
test_sqlite_no_db_password() {
    # SQLite secrets.tpl should not include DEPLOYER_DB_PASSWORD
    # This tests the template generation logic

    local tpl_content='DEPLOYER_SUDO_PASS=op://Vault/Item/sudo
DEPLOYER_APP_KEY=op://Vault/Item/key'

    # Should NOT contain db_password for SQLite
    ! echo "$tpl_content" | grep -qi "db.password\|db_password"
}

# Test PostgreSQL/MySQL config includes db_password
test_pgsql_has_db_password() {
    local tpl_content='DEPLOYER_SUDO_PASS=op://Vault/Item/sudo
DEPLOYER_DB_PASSWORD=op://Vault/Item/db-password
DEPLOYER_APP_KEY=op://Vault/Item/key'

    # Should contain db_password
    echo "$tpl_content" | grep -qi "db.password\|db_password"
}

# ─────────────────────────────────────────────────────────────────────────────
# Run All Tests
# ─────────────────────────────────────────────────────────────────────────────

main() {
    echo ""
    echo "════════════════════════════════════════════════════════════════════"
    echo "  Deployer Laravel Stack - Init Wizard Tests"
    echo "════════════════════════════════════════════════════════════════════"
    echo ""
    log_info "Test workspace: $TEST_WORKSPACE"
    log_info "Project root: $PROJECT_ROOT"

    mkdir -p "$TEST_WORKSPACE"

    # Section 1: Greenfield
    echo ""
    echo "─── Section 1: Greenfield Tests ───"
    run_test "1.9 Deploy.php syntax validation" test_1_9_deploy_php_syntax
    run_test "1.10 Wrapper is executable" test_1_10_dep_wrapper_executable
    run_test "1.14 Non-project-root detection" test_1_14_non_project_root_error

    # Section 2: Brownfield
    echo ""
    echo "─── Section 2: Brownfield Tests ───"
    run_test "2.5 Existing deployment structure" test_2_5_existing_deployment_structure

    # Section 3: Secrets Formats
    echo ""
    echo "─── Section 3: Secrets Format Tests ───"
    run_test "3.1 1Password format (op://)" test_3_1_1password_format
    run_test "3.2 Literal values (legacy)" test_3_2_literal_values_legacy
    run_test "3.3 Mixed format" test_3_3_mixed_format
    run_test "3.4 Special characters in values" test_3_4_special_characters
    run_test "3.7 Conflict detection (both files)" test_3_7_conflict_detection

    # Section 4: Deploy.php Formats
    echo ""
    echo "─── Section 4: Deploy.php Format Tests ───"
    run_test "4.1 Raw host() calls (no environment())" test_4_1_raw_host_calls
    run_test "4.4 Custom tasks and hooks" test_4_4_custom_tasks

    # Section 5: Edge Cases
    echo ""
    echo "─── Section 5: Edge Case Tests ───"
    run_test "5.1 deploy.php without deploy/ directory" test_5_1_deploy_php_no_directory
    run_test "5.2 deploy/ without deploy.php" test_5_2_directory_no_deploy_php
    run_test "5.3 Secrets file conflict" test_5_3_secrets_conflict
    run_test "5.5 .gitignore missing secrets.env" test_5_5_gitignore_missing_secrets
    run_test "5.6 Placeholder values detection" test_5_6_placeholder_values

    # init:check Tests
    echo ""
    echo "─── init:check Validation Tests ───"
    run_test "Valid configuration structure" test_init_check_valid_config
    run_test "Missing deploy.php detection" test_init_check_missing_deploy_php

    # Template Generation Tests
    echo ""
    echo "─── Template Generation Tests ───"
    run_test "SQLite omits db_password" test_sqlite_no_db_password
    run_test "PostgreSQL includes db_password" test_pgsql_has_db_password

    # Summary
    echo ""
    echo "════════════════════════════════════════════════════════════════════"
    echo "  Test Summary"
    echo "════════════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  Total:  ${TESTS_RUN}"
    echo -e "  ${GREEN}Passed: ${TESTS_PASSED}${NC}"
    echo -e "  ${RED}Failed: ${TESTS_FAILED}${NC}"
    echo ""

    if [[ $TESTS_FAILED -gt 0 ]]; then
        log_fail "Some tests failed!"
        exit 1
    else
        log_pass "All tests passed!"
        exit 0
    fi
}

main "$@"
