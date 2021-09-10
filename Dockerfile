FROM alma-installments-prestashop_prestashop_1

RUN apt-get update -yq \
    && apt-get install -y nano

WORKDIR /