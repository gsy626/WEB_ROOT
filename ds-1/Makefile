# =============================================================================
# ds-1 · 数理主题首页
# 零依赖：原生 ES Module + Canvas 2D
# =============================================================================
PYTHON ?= python3
PORT   ?= 8791
BIDI   ?= 9333

.PHONY: help serve build check audit check-boot clean dist-clean

help:
	@echo "make serve   启动本地静态服务 (http://127.0.0.1:$(PORT))"
	@echo "make build   打包为 dist/index.html 单文件"
	@echo "make check   静态校验：语法 + 导入/导出图"
	@echo "make audit   无头浏览器全页巡检，截图输出到 _shots/"
	@echo "make check-boot  黑屏专项检查（三个入口是否会停在黑场）"
	@echo "make clean   清理临时产物"

serve:
	@echo "→ http://127.0.0.1:$(PORT)/index.html"
	@$(PYTHON) -m http.server $(PORT) --bind 127.0.0.1

build:
	@$(PYTHON) build.py

check:
	@node --experimental-vm-modules tools/check-modules.mjs

audit:
	@PORT_HTTP=$(PORT) PORT_BIDI=$(BIDI) bash tools/run-audit.sh

check-boot:
	@PORT_HTTP=$(PORT) PORT_BIDI=$(BIDI) bash tools/run-check-boot.sh

clean:
	@rm -rf .tmp
	@echo "已清理 .tmp"

dist-clean: clean
	@rm -rf dist _shots
	@echo "已清理 dist 与 _shots"
