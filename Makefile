# emRecupel - Makefile for Release Management
# =============================================

# Configuration
MODULE_NAME := emrecupel
MODULE_FILE := core/modules/modEmrecupel.class.php
RELEASE_DIR ?= $(CURDIR)/dist
BUILD_DIR := build

# Extract version from module file
VERSION := $(shell grep -oP "\\\$$this->version\s*=\s*'\K[^']+" $(MODULE_FILE))

# Release filename: module_<name>-x.y.z.zip (Dolibarr module zip convention)
RELEASE_FILENAME := module_$(MODULE_NAME)-$(VERSION).zip

# Colors
GREEN := \033[0;32m
YELLOW := \033[0;33m
RED := \033[0;31m
NC := \033[0m

.PHONY: help version lint test build-release tag release clean check-git-clean

# Default target
help:
	@echo "emRecupel Release Management"
	@echo "============================"
	@echo ""
	@echo "Current version: $(VERSION)"
	@echo "Release file:    $(RELEASE_FILENAME)"
	@echo ""
	@echo "Available commands:"
	@echo "  make version       - Show current module version"
	@echo "  make lint          - php -l on every PHP file"
	@echo "  make test          - PHPUnit unit tests"
	@echo "  make build-release - Build ZIP package in $(RELEASE_DIR)/"
	@echo "  make tag           - Create git tag v$(VERSION) and push"
	@echo "  make release       - Create tag + build release"
	@echo "  make clean         - Remove build artifacts"

version:
	@echo "Module version: $(VERSION)"

# Files and folders shipped in the zip (whitelist: anything else stays out,
# in particular dev/, tests/, vendor/, marketing/, composer.*).
SHIP_DIRS  := admin class core css data docs img langs lib sql
SHIP_FILES := $(wildcard *.php) CHANGELOG.md README.md LICENSE
# Entry points that must be in the zip (sanity check before zipping).
ENTRYPOINTS := core/modules/modEmrecupel.class.php class/actions_emrecupel.class.php \
	core/triggers/interface_99_modEmrecupel_EmrecupelTriggers.class.php \
	product.php document.php classify.php import.php scale.php switch.php declaration.php index.php \
	admin/setup.php admin/about.php lib/emrecupel.lib.php \
	data/recupel-scale-2026.csv data/recupel-scale-2027.csv \
	langs/fr_FR/emrecupel.lang langs/en_US/emrecupel.lang langs/nl_NL/emrecupel.lang

# Syntax check of every PHP file of the module
lint:
	@find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
	@find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -print0 | xargs -0 -n1 php -l > /dev/null

# Unit tests (PHPUnit installed with: composer install, inside the web container)
test:
	php vendor/bin/phpunit -c phpunit.xml.dist

# Build release ZIP
build-release:
	@echo "$(GREEN)Building emRecupel v$(VERSION)...$(NC)"
	@rm -rf $(BUILD_DIR)
	@mkdir -p $(RELEASE_DIR) $(BUILD_DIR)/$(MODULE_NAME)
	@for d in $(SHIP_DIRS); do if [ -d $$d ]; then cp -r $$d $(BUILD_DIR)/$(MODULE_NAME)/; fi; done
	@cp $(SHIP_FILES) $(BUILD_DIR)/$(MODULE_NAME)/
	@find $(BUILD_DIR)/$(MODULE_NAME) \( -name '.git*' -o -name '.DS_Store' -o -name '*.swp' -o -name '*~' \) -prune -exec rm -rf {} +
	@# README screenshots are for GitHub only, not needed at runtime.
	@rm -rf $(BUILD_DIR)/$(MODULE_NAME)/docs/screenshots
	@for f in $(ENTRYPOINTS); do \
		if [ ! -f $(BUILD_DIR)/$(MODULE_NAME)/$$f ]; then echo "$(RED)Missing in package: $$f$(NC)"; exit 1; fi; \
	done
	@if grep -rq "this->version = '$(VERSION)'" $(BUILD_DIR)/$(MODULE_NAME)/core/modules/modEmrecupel.class.php && \
		grep -q "MODULE_VERSION = '$(VERSION)'" $(BUILD_DIR)/$(MODULE_NAME)/class/emrecupelmigrations.class.php && \
		grep -q "EMRECUPEL_DB_VERSION') !== '$(VERSION)'" $(BUILD_DIR)/$(MODULE_NAME)/class/actions_emrecupel.class.php && \
		grep -qx "$(VERSION)" VERSION; then :; \
		else echo "$(RED)Version mismatch between descriptor, EmrecupelMigrations::MODULE_VERSION, ActionsEmrecupel::printCommonFooter and VERSION$(NC)"; exit 1; fi
	@rm -f $(RELEASE_DIR)/$(RELEASE_FILENAME) $(RELEASE_DIR)/$(RELEASE_FILENAME).sha256
	@cd $(BUILD_DIR) && zip -qr $(RELEASE_DIR)/$(RELEASE_FILENAME) $(MODULE_NAME)/
	@cd $(RELEASE_DIR) && sha256sum $(RELEASE_FILENAME) > $(RELEASE_FILENAME).sha256
	@rm -rf $(BUILD_DIR)
	@echo "$(GREEN)Release built successfully!$(NC)"
	@echo "  ZIP: $(RELEASE_DIR)/$(RELEASE_FILENAME)"
	@echo "  SHA: $(RELEASE_DIR)/$(RELEASE_FILENAME).sha256"
	@ls -lh $(RELEASE_DIR)/$(RELEASE_FILENAME)

# Check if git working directory is clean
check-git-clean:
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "$(RED)Error: Working directory is not clean.$(NC)"; \
		git status --short; \
		exit 1; \
	fi

# Create and push git tag
tag: check-git-clean
	@echo "$(GREEN)Creating git tag v$(VERSION)...$(NC)"
	@if git rev-parse "v$(VERSION)" >/dev/null 2>&1; then \
		echo "$(RED)Error: Tag v$(VERSION) already exists!$(NC)"; \
		exit 1; \
	fi
	@git tag -a "v$(VERSION)" -m "Release v$(VERSION)"
	@git push origin "v$(VERSION)"

release: tag build-release
	@echo ""
	@echo "$(GREEN)Release v$(VERSION) complete!$(NC)"

clean:
	@echo "Cleaning build artifacts..."
	@rm -rf $(BUILD_DIR)
	@echo "$(GREEN)Done.$(NC)"
