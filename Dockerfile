FROM composer:1.4

RUN apk --no-cache add git

# add a non-root user and give them ownership
RUN adduser -D -u 9000 app && \
    # repo
    mkdir /repo && \
    chown -R app:app /repo && \
    # actor code
    mkdir /usr/src/actor && \
    chown -R app:app /usr/src/actor && \
    # composer cache
    chown -R app:app /composer/cache

# run everything from here on as non-root
USER app

RUN git config --global user.email "bot@dependencies.io"
RUN git config --global user.name "Dependencies.io Bot"

ADD entrypoint.php /usr/src/actor

WORKDIR /repo

ENTRYPOINT ["php", "/usr/src/actor/entrypoint.php"]
