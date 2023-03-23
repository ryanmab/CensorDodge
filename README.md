# Censor Dodge Web Proxy

<img src="https://www.censordodge.com/wp-content/uploads/2017/11/logo.svg" width="80px" />

**Current Stable Release:** V1.83

[Censor Dodge](https://censordodge.com/) is a lightweight and customisable proxy script built on PHP. The standalone library is intended to act as an extensible system that is easily customisable with plugins and themes.

It started simply as a personal project prompted by the proxy sites on the market being slow, ridden with popups and running outdated proxy software, and over the last 6 years has grown into a complete software solution.

This package also contains a starter theme, called **Surf** which can be easily customised (custom background images), or used as a basis for a new custom design.

## Requirements

- PHP 5.1 or higher
- cURL Library
- DOMDocument Library

## Demonstration

A full demonstration of the script is available free of change on [CensorDodge.com](https://censordodge.com/).

This includes an approved and automatic one-click proxy setup tool, which enables easy creation of custom web proxies at a [click of a button](https://censordodge.com/#setup).

## Running Locally

Censor Dodge can be run locally using Docker:

  ```sh
  docker run -p 80:80 -it ghcr.io/ryanmab/censordodge
  ```

## Hosting

Censor Dodge will run on any basic PHP hosting (people have even had success running Censor Dodge on $1 per year shared hosting). Alternatively, you can run the project as a Docker image (using the `ghcr.io/ryanmab/censordodge` image).

No installation steps are needed, the script is completely pre-configured. The only thing you need to ensure is that you have enough bandwidth to handle the traffic, and you can sufficiently scale the solution as you get more visitors.

## Author

By Ryan Maber - [View Portfolio](https://ryanmaber.com/)

## Contributors

- [Mossroy](https://github.com/mossroy)
- [abcnet-lu](https://github.com/abcnet-lu)
