#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}=== React App Deployment Script ===${NC}"

# Check if React app path is provided
if [ -z "$1" ]; then
    echo -e "${RED}Error: Please provide the path to your React app${NC}"
    echo "Usage: ./deploy-react.sh /path/to/your/react-app"
    exit 1
fi

REACT_APP_PATH="$1"
LARAVEL_PUBLIC_PATH="$(pwd)/public/react"

# Check if React app directory exists
if [ ! -d "$REACT_APP_PATH" ]; then
    echo -e "${RED}Error: React app directory not found: $REACT_APP_PATH${NC}"
    exit 1
fi

# Navigate to React app directory
cd "$REACT_APP_PATH" || exit 1

echo -e "${YELLOW}Building React app...${NC}"

# Check if package.json exists
if [ ! -f "package.json" ]; then
    echo -e "${RED}Error: package.json not found in React app directory${NC}"
    exit 1
fi

# Build the React app
if command -v bun &> /dev/null; then
    echo "Using bun to build..."
    bun run build
elif command -v npm &> /dev/null; then
    echo "Using npm to build..."
    npm run build
else
    echo -e "${RED}Error: Neither npm nor bun found. Please install Node.js${NC}"
    exit 1
fi

# Check if build was successful
if [ ! -d "dist" ]; then
    echo -e "${RED}Error: Build failed. 'dist' directory not found${NC}"
    exit 1
fi

echo -e "${GREEN}✓ React app built successfully${NC}"

# Create Laravel public/react directory if it doesn't exist
echo -e "${YELLOW}Creating Laravel public/react directory...${NC}"
mkdir -p "$LARAVEL_PUBLIC_PATH"

# Copy built files to Laravel public directory
echo -e "${YELLOW}Copying files to Laravel...${NC}"
cp -r dist/* "$LARAVEL_PUBLIC_PATH/"

# Check if copy was successful
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Files copied successfully to: $LARAVEL_PUBLIC_PATH${NC}"
    
    # Set proper permissions
    chmod -R 755 "$LARAVEL_PUBLIC_PATH"
    echo -e "${GREEN}✓ Permissions set${NC}"
    
    # List deployed files
    echo -e "${YELLOW}Deployed files:${NC}"
    ls -lah "$LARAVEL_PUBLIC_PATH"
    
    echo -e "${GREEN}=== Deployment Complete! ===${NC}"
    echo -e "Visit ${YELLOW}http://127.0.0.1:8000/${NC} to see your React app"
else
    echo -e "${RED}Error: Failed to copy files${NC}"
    exit 1
fi


