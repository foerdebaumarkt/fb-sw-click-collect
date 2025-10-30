.PHONY: clean-build build-zip build

SHOPWARE_CLI ?= shopware-cli
BUILD_DIR ?= build
PLUGIN_NAME ?= FbClickCollect
PLUGIN_ZIP ?= $(BUILD_DIR)/$(PLUGIN_NAME).zip

clean-build:
	rm -rf "$(BUILD_DIR)"

build-zip: clean-build
	$(SHOPWARE_CLI) extension zip . --disable-git --output-directory "$(BUILD_DIR)" --filename "$(PLUGIN_NAME).zip"

build: build-zip
