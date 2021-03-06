install:
	composer install

lint:
	composer exec --verbose phpcs -- --standard=PSR12 src tests --ignore=*/fixtures/*
	composer exec --verbose phpstan -- --level=8 analyse src tests

lint-fix:
	composer exec --verbose phpcbf -- --standard=PSR12 src tests

test:
	composer exec --verbose phpunit tests

test-debug:
	composer exec --verbose mode=DEBUG phpunit tests

test-coverage:
	composer exec --verbose phpunit tests -- --coverage-clover build/logs/clover.xml
